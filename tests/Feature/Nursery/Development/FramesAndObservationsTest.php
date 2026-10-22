<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Development;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Sync\Contracts\BatchContext;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItem;
use Scholaris\Modules\Socle\Sync\Contracts\BatchItemHandler;
use Scholaris\Modules\Socle\Sync\Contracts\ItemResult;

/** EX-MAT-16 (US10), RG-MAT-1 : référentiel exemple copié puis activé par l'école ; observation sans note, niveaux paramétrés, hors ligne idempotent. */
final class FramesAndObservationsTest extends DevelopmentTestCase
{
    protected function setUp(): void
    {
        $this->bootDevelopment();
    }

    public function testLeReferentielExempleDeLaPlateformeEstMarqueAValiderEtNeSeModifiePas(): void
    {
        $frames = $this->ok('GET', '/nursery/frames');
        $platform = array_values(array_filter($frames, static fn (array $f): bool => $f['scope'] === 'platform'));
        self::assertCount(1, $platform);
        self::assertTrue($platform[0]['to_validate']);
        self::assertSame('example', $platform[0]['origin']);
        $detail = $this->ok('GET', '/nursery/frames/' . $platform[0]['id']);
        self::assertCount(5, $detail['domains']);
        self::assertCount(2, $detail['domains'][0]['items']);
        self::assertSame(404, $this->code('POST', '/nursery/frames/' . $platform[0]['id'] . '/domains', ['code' => 'X', 'label_fr' => 'X', 'label_en' => 'X']));
        self::assertSame(404, $this->code('POST', '/nursery/frames/' . $platform[0]['id'] . '/activate', []));
    }

    public function testL_ecoleCopieAdapteEtActiveEtUneNouvelleVersionArchiveLAncienne(): void
    {
        $domain = $this->ok('GET', '/nursery/frames/' . $this->frameId)['domains'][0];
        self::assertSame('active', $this->ok('GET', '/nursery/frames/' . $this->frameId)['status']);
        self::assertSame(409, $this->code('POST', '/nursery/frames/' . $this->frameId . '/domains', ['code' => 'NEW', 'label_fr' => 'Nouveau', 'label_en' => 'New']), 'Un référentiel actif ne se modifie plus.');

        $copy = $this->ok('POST', '/nursery/frames/' . $this->frameId . '/copy', []);
        self::assertSame(2, $copy['version']);
        self::assertSame('draft', $copy['status']);
        $withDomain = $this->ok('POST', '/nursery/frames/' . $copy['id'] . '/domains', ['code' => 'ECOLE', 'label_fr' => 'Domaine de l’école', 'label_en' => 'School domain']);
        $newDomain = end($withDomain['domains']);
        $this->ok('POST', '/nursery/frames/' . $copy['id'] . '/items', ['domain_id' => $newDomain['id'], 'code' => 'ECOLE-1', 'label_fr' => 'Item propre', 'label_en' => 'Own item']);
        $this->ok('PATCH', '/nursery/frames/' . $copy['id'] . '/items/' . $domain['items'][0]['id'], ['active' => false, 'label_fr' => 'Libellé adapté']);
        $this->ok('POST', '/nursery/frames/' . $copy['id'] . '/activate', []);

        self::assertSame('archived', $this->ok('GET', '/nursery/frames/' . $this->frameId)['status']);
        self::assertSame('active', $this->ok('GET', '/nursery/frames/' . $copy['id'])['status']);
        // L'ancien item n'est plus observable ; l'item de l'ancien référentiel archivé non plus.
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired'], 'educatrice-a'));
    }

    public function testUnReferentielOfficielExigeSaReferenceDeValidation(): void
    {
        $pdo = $this->service(ConnectionFactory::class)->pdo();
        $pdo->exec("INSERT INTO nu_dev_frames (id, tenant_id, establishment_id, scope, origin, frame_code, version, label_fr, label_en, status, created_at) VALUES ('01OFFICIAL00000000000000A', '" . self::TA . "', '" . self::EA . "', 'tenant', 'official', 'OFF', 1, 'Officiel', 'Official', 'draft', '2026-10-21T08:00:00+01:00')");
        $pdo->exec("INSERT INTO nu_dev_domains (id, tenant_id, establishment_id, frame_id, code, label_fr, label_en, sort_order) VALUES ('01OFFICIALDOM0000000000A', '" . self::TA . "', '" . self::EA . "', '01OFFICIAL00000000000000A', 'D', 'D', 'D', 1)");
        $pdo->exec("INSERT INTO nu_dev_items (id, tenant_id, establishment_id, frame_id, domain_id, code, label_fr, label_en, sort_order, active) VALUES ('01OFFICIALITM0000000000A', '" . self::TA . "', '" . self::EA . "', '01OFFICIAL00000000000000A', '01OFFICIALDOM0000000000A', 'I', 'I', 'I', 1, 1)");
        self::assertSame(422, $this->code('POST', '/nursery/frames/01OFFICIAL00000000000000A/activate', []));
        self::assertSame(200, $this->code('POST', '/nursery/frames/01OFFICIAL00000000000000A/activate', ['validation_ref' => 'ARRETE-FICTIF-001']));
    }

    public function testSeulLaDirectriceGereLeReferentielEtUnAutreClientNeVoitPasLeSien(): void
    {
        self::assertSame(403, $this->code('POST', '/nursery/frames/' . $this->frameId . '/copy', [], 'educatrice-a'));
        self::assertSame(200, $this->code('GET', '/nursery/frames/' . $this->frameId, null, 'educatrice-a'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('GET', '/nursery/frames/' . $this->frameId, null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/frames/' . $this->frameId . '/copy', [], 'directrice-b', self::TB, self::EB));
    }

    public function testUneObservationEnregistreUnNiveauParametreSansAucuneNoteNiRang(): void
    {
        $observation = $this->ok('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'in_progress', 'remark' => 'Fait des phrases de plus en plus longues.'], 'educatrice-a');
        self::assertSame('in_progress', $observation['level_code']);
        $payload = (string) json_encode($observation);
        foreach (['score', 'grade', 'average', 'rank', 'total', 'mark', 'note"'] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden, $payload);
        }
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'excellent'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => 'inconnu', 'level_code' => 'acquired'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'observed_on' => '2026-12-01'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'score' => 10], 'educatrice-a'), 'Aucune note acceptée.');
    }

    public function testLesNiveauxVientDesParametresDeLEcole(): void
    {
        $this->setSetting(self::TA, self::EA, 'nursery.dev.levels', ['maitrise', 'en_cours']);
        self::assertSame(201, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'maitrise'], 'educatrice-a'));
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired'], 'educatrice-a'));
    }

    public function testUnTermeAEviterBloqueLaRemarque(): void
    {
        $this->setSetting(self::TA, self::EA, 'nursery.dev.discouraged_terms', ['nul', 'retardé']);
        self::assertSame(422, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'remark' => 'Dessin NUL ce matin'], 'educatrice-a'));
        self::assertSame(201, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired', 'remark' => 'Un dessin très coloré'], 'educatrice-a'), 'Mot entier seulement.');
    }

    public function testLEducatriceNObservePasLesEnfantsDUneAutreSectionEtLesAutresClientsNeVoientRien(): void
    {
        $body = ['learner_id' => $this->bao['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired'];
        self::assertSame(403, $this->code('POST', '/nursery/observations', $body, 'educatrice-a'));
        self::assertContains('nursery.scope.denied', $this->audit->actions());
        self::assertSame(404, $this->code('GET', '/nursery/observations?learner_id=' . $this->bao['child'], null, 'educatrice-a'));
        $this->ok('POST', '/nursery/observations', ['learner_id' => $this->ada['child']] + $body, 'educatrice-a');
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        self::assertSame(404, $this->code('GET', '/nursery/observations?learner_id=' . $this->ada['child'], null, 'directrice-b', self::TB, self::EB));
        self::assertSame(404, $this->code('POST', '/nursery/observations', ['learner_id' => $this->ada['child']] + $body, 'directrice-b', self::TB, self::EB));
    }

    public function testLeRejeuParClientUuidNeDoublonnePas(): void
    {
        $body = ['learner_id' => $this->ada['child'], 'item_id' => $this->items['MOTR-1'], 'level_code' => 'acquired', 'client_uuid' => 'obs-0001'];
        self::assertSame(201, $this->code('POST', '/nursery/observations', $body, 'educatrice-a'));
        self::assertSame(200, $this->code('POST', '/nursery/observations', $body, 'educatrice-a'));
        self::assertCount(1, $this->ok('GET', '/nursery/observations?learner_id=' . $this->ada['child'], null, 'educatrice-a'));
    }

    public function testLeSuiviDeFrequenceListeLesEcartsParDomaineSansComparerLesEnfants(): void
    {
        $gaps = $this->ok('GET', '/nursery/observations/coverage?section_id=' . $this->sectionA . '&month=2026-10', null, 'educatrice-a');
        self::assertCount(5, $gaps, 'Un enfant et cinq domaines sans observation.');
        self::assertSame(1, $gaps[0]['required']);
        $this->ok('POST', '/nursery/observations', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['LANG-1'], 'level_code' => 'acquired'], 'educatrice-a');
        self::assertCount(4, $this->ok('GET', '/nursery/observations/coverage?section_id=' . $this->sectionA . '&month=2026-10', null, 'educatrice-a'));
        self::assertSame(404, $this->code('GET', '/nursery/observations/coverage?section_id=' . $this->sectionB, null, 'educatrice-a'));
        self::assertSame(422, $this->code('GET', '/nursery/observations/coverage?section_id=' . $this->sectionA . '&month=octobre', null, 'educatrice-a'));
        $this->setSetting(self::TA, self::EA, 'nursery.dev.min_observations_per_domain_per_month', 2);
        self::assertCount(5, $this->ok('GET', '/nursery/observations/coverage?section_id=' . $this->sectionA . '&month=2026-10', null, 'educatrice-a'));
    }

    public function testLeGestionnaireHorsLigneEstIdempotentEtControleLeNiveau(): void
    {
        $handler = $this->kernel->container()->get('sync.handlers.nursery.observation');
        self::assertInstanceOf(BatchItemHandler::class, $handler);
        $context = new BatchContext(new Principal('educatrice-a', self::TA), self::EA, 'appareil-01', 'lot-01');
        $item = new BatchItem('nursery.observation', 'obs-hl-0001', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['ARTS-1'], 'level_code' => 'in_progress']);
        self::assertSame(ItemResult::APPLIED, $handler->handle($item, $context)->status);
        self::assertSame(ItemResult::DUPLICATE, $handler->handle($item, $context)->status);
        try {
            $handler->handle(new BatchItem('nursery.observation', 'obs-hl-0002', ['learner_id' => $this->ada['child'], 'item_id' => $this->items['ARTS-1'], 'level_code' => 'parfait']), $context);
            self::fail('Un niveau hors des codes paramétrés doit être rejeté.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }
}
