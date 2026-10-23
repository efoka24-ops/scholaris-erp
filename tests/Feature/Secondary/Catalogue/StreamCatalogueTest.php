<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Catalogue;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Secondary\Catalogue\Contracts\StreamDirectory;
use Scholaris\Modules\Apps\Secondary\Support\PlatformAction;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * Table de référence des séries et spécialités (catalogue-series-minesec.md, EX-SEC-06, Q22) : amorçage MINESEC « à confirmer »,
 * filtre par le profil, ajouts avec source, masquage et renommage par établissement avec historique, administration de plateforme.
 */
final class StreamCatalogueTest extends SecondaryTestCase
{
    private const READ = ['secondary.catalogue.read'];
    private const MANAGE = ['secondary.catalogue.read', 'secondary.settings.manage'];

    protected function setUp(): void
    {
        $this->bootSecondary();
    }

    public function testAmorcageDuCatalogueMinesec(): void
    {
        $rows = $this->pdo()->query("SELECT code, cycle_code, teaching_kind, track_code, origin, confirmation, label_fr FROM se_stream_refs WHERE tenant_id = '*'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(107, $rows);
        $keys = array_map(static fn (array $r): string => $r['code'] . '/' . $r['cycle_code'], $rows);
        self::assertSame($keys, array_values(array_unique($keys)), 'Clé (code, cycle) unique.');
        foreach (['ESF', 'PA', 'PV', 'CERAM', 'PEINT', 'SCULP'] as $code) {
            self::assertContains($code . '/first', $keys);
            self::assertContains($code . '/second', $keys);
        }
        $codes = array_column($rows, 'code');
        foreach (['MAGE', 'TEP', 'ISRH', 'E', 'ABI'] as $absent) {
            self::assertNotContains($absent, $codes, $absent . ' ne doit pas être amorcé (décision du propriétaire).');
        }
        self::assertNotContains('SH/second', $keys, 'Série SH (sciences humaines) non amorcée.');
        self::assertContains('SH/first', $keys, 'SH « service hôtelier » (STT, 1er cycle) vient bien du MINESEC.');
        self::assertSame(['minesec'], array_values(array_unique(array_column($rows, 'origin'))));
        self::assertSame(['to_confirm'], array_values(array_unique(array_column($rows, 'confirmation'))));
        $general = array_filter($rows, static fn (array $r): bool => $r['teaching_kind'] === 'general');
        self::assertCount(10, $general);
        self::assertSame(['second'], array_values(array_unique(array_column($general, 'cycle_code'))));
        $labels = array_column($rows, 'label_fr', 'code');
        self::assertSame('Chaudronnerie', $labels['CH'], 'Coquille de la source corrigée.');
        self::assertSame('Employé des services comptables', $labels['ESCOM']);
    }

    public function testLeProfilFiltreLesSeriesProposees(): void
    {
        $directory = $this->service(StreamDirectory::class);
        $ces = $directory->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'ces'));
        self::assertSame([], $ces, 'Un CES (1er cycle général) ne propose aucune série du second cycle.');

        $cetic = $directory->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'cetic'));
        self::assertNotSame([], $cetic);
        foreach ($cetic as $stream) {
            self::assertSame(['first', 'technical'], [$stream->cycleCode, $stream->teachingKind]);
        }

        $leg = $directory->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'leg'));
        self::assertCount(10, $leg);
        self::assertSame(['series'], array_values(array_unique(array_map(static fn ($s): string => $s->streamKind, $leg))));

        $technique = $directory->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'lycee_technique'));
        self::assertCount(97, $technique);
        self::assertSame(['IND', 'STT'], array_values(array_unique(array_map(static fn ($s): string => $s->trackCode, $technique))));

        $autre = $directory->forEstablishment(self::TA, $this->factory->establishment(self::TA, 'autre'));
        self::assertCount(107, $autre, 'Profil « autre » pas encore réglé : aucun filtre.');
    }

    public function testListePagineeEtFiltrable(): void
    {
        $school = $this->factory->establishment(self::TA, 'lycee_technique');
        $this->grant(self::TA, self::ADMIN, $school, self::READ);
        [$status, $page] = $this->call('GET', '/secondary/catalogue/streams?per_page=5&filter[track_code]=STT&filter[cycle_code]=first', null, self::ADMIN, self::TA, $school);
        self::assertSame(200, $status);
        self::assertCount(5, $page['data']);
        self::assertNotNull($page['meta']['next_cursor']);
        [, $next] = $this->call('GET', '/secondary/catalogue/streams?per_page=5&filter[track_code]=STT&filter[cycle_code]=first&cursor=' . $page['meta']['next_cursor'], null, self::ADMIN, self::TA, $school);
        self::assertNotSame($page['data'][0]['id'], $next['data'][0]['id']);
        self::assertSame(422, $this->call('GET', '/secondary/catalogue/streams?filter[inconnu]=x', null, self::ADMIN, self::TA, $school)[0]);
        self::assertSame(422, $this->call('GET', '/secondary/catalogue/streams?per_page=201', null, self::ADMIN, self::TA, $school)[0]);
        [, $all] = $this->call('GET', '/secondary/catalogue/streams?scope=all&filter[teaching_kind]=general&per_page=50', null, self::ADMIN, self::TA, $school);
        self::assertCount(10, $all['data'], 'scope=all montre aussi les séries hors profil.');
    }

    public function testMasquerEtRenommerPourLEtablissementSeulAvecHistorique(): void
    {
        $school = $this->factory->establishment(self::TA, 'leg');
        $other = $this->factory->establishment(self::TA, 'leg', 'e-leg-deux');
        $this->grant(self::TA, self::ADMIN, $school, self::MANAGE);
        $this->grant(self::TA, self::ADMIN, $other, self::READ);
        $a1 = $this->streamId($school, 'A1');

        self::assertSame(422, $this->call('PATCH', '/secondary/catalogue/streams/' . $a1, ['hidden' => true], self::ADMIN, self::TA, $school)[0], 'Motif obligatoire.');
        $renamed = $this->ok('PATCH', '/secondary/catalogue/streams/' . $a1, ['label_fr' => 'Lettres classiques (A1)', 'reason' => 'Libellé de l\'établissement'], self::ADMIN, self::TA, $school);
        self::assertSame('Lettres classiques (A1)', $renamed['label_fr']);
        self::assertSame('Lettres, latin et grec', $renamed['reference_label_fr']);
        self::assertTrue($renamed['renamed']);
        $hidden = $this->ok('PATCH', '/secondary/catalogue/streams/' . $a1, ['hidden' => true, 'reason' => 'Série non ouverte cette année'], self::ADMIN, self::TA, $school);
        self::assertTrue($hidden['hidden']);
        self::assertSame(['renamed', 'hidden'], array_column($hidden['history'], 'change_kind'));
        self::assertSame('Série non ouverte cette année', $hidden['history'][1]['reason']);

        $visible = array_column($this->call('GET', '/secondary/catalogue/streams?per_page=50', null, self::ADMIN, self::TA, $school)[1]['data'], 'code');
        self::assertNotContains('A1', $visible, 'Masquée pour cet établissement.');
        $withHidden = array_column($this->call('GET', '/secondary/catalogue/streams?per_page=50&include_hidden=1', null, self::ADMIN, self::TA, $school)[1]['data'], 'code');
        self::assertContains('A1', $withHidden);
        $elsewhere = $this->ok('GET', '/secondary/catalogue/streams/' . $a1, null, self::ADMIN, self::TA, $other);
        self::assertSame(['Lettres, latin et grec', false, []], [$elsewhere['label_fr'], $elsewhere['hidden'], $elsewhere['history']], 'Un autre établissement ne voit ni le renommage ni le masquage.');
        $platform = $this->pdo()->query("SELECT label_fr, hidden, version_no FROM se_stream_refs WHERE code = 'A1'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['Lettres, latin et grec', 0, 1], [$platform['label_fr'], (int) $platform['hidden'], (int) $platform['version_no']], 'La ligne de la plateforme n\'est jamais modifiée par un établissement.');

        $shown = $this->ok('PATCH', '/secondary/catalogue/streams/' . $a1, ['hidden' => false, 'label_fr' => null, 'reason' => 'Réouverture'], self::ADMIN, self::TA, $school);
        self::assertSame(['Lettres, latin et grec', false], [$shown['label_fr'], $shown['hidden']]);
        self::assertSame(['renamed', 'hidden', 'shown', 'renamed'], array_column($shown['history'], 'change_kind'));
    }

    public function testAjoutParUnEtablissementAvecSource(): void
    {
        $school = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $school, self::MANAGE);
        [$status, $problem] = $this->call('POST', '/secondary/catalogue/streams', [], self::ADMIN, self::TA, $school);
        self::assertSame(422, $status);
        foreach (['code', 'cycle_code', 'teaching_kind', 'label_fr', 'source_ref'] as $field) {
            self::assertArrayHasKey($field, $problem['errors']);
        }
        self::assertSame(422, $this->call('POST', '/secondary/catalogue/streams', ['code' => 'C', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'Doublon', 'source_ref' => 'x'], self::ADMIN, self::TA, $school)[0], 'Code déjà dans la liste de la plateforme.');
        self::assertSame(422, $this->call('POST', '/secondary/catalogue/streams', ['code' => 'MAGE', 'cycle_code' => 'second', 'teaching_kind' => 'technical', 'label_fr' => 'Sans filière', 'source_ref' => 'x'], self::ADMIN, self::TA, $school)[0], 'Filière obligatoire au technique.');
        self::assertSame(422, $this->call('POST', '/secondary/catalogue/streams', ['code' => 'XX', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'X', 'source_ref' => 'x', 'inconnu' => 1], self::ADMIN, self::TA, $school)[0]);

        $created = $this->ok('POST', '/secondary/catalogue/streams', ['code' => 'abi', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'A4 bilingue', 'source_ref' => 'Arrêté fictif de test n° 0001'], self::ADMIN, self::TA, $school);
        self::assertSame(['ABI', 'establishment', 'establishment', 'custom', 'series', 'GEN'], [$created['code'], $created['scope'], $created['origin'], $created['confirmation'], $created['stream_kind'], $created['track_code']]);
        self::assertSame(['created'], array_column($created['history'], 'change_kind'));
        self::assertContains('secondary.stream.created', $this->audit->actions());
        $renamed = $this->ok('PATCH', '/secondary/catalogue/streams/' . $created['id'], ['label_fr' => 'Série A4 bilingue', 'reason' => 'Précision'], self::ADMIN, self::TA, $school);
        self::assertSame(['Série A4 bilingue', 2], [$renamed['label_fr'], $renamed['version_no']]);

        $ces = $this->factory->establishment(self::TA, 'ces');
        $this->grant(self::TA, self::ADMIN, $ces, self::MANAGE);
        [$status, $problem] = $this->call('POST', '/secondary/catalogue/streams', ['code' => 'ABI', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'A4 bilingue', 'source_ref' => 'x'], self::ADMIN, self::TA, $ces);
        self::assertSame(422, $status, 'Cycle absent du profil (RG-ORG-1).');
        self::assertArrayHasKey('cycle_code', $problem['errors']);
    }

    public function testDroitsEtIsolationEntreClients(): void
    {
        $school = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $school, self::MANAGE);
        $created = $this->ok('POST', '/secondary/catalogue/streams', ['code' => 'XLOC', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'Série locale fictive', 'source_ref' => 'Note fictive'], self::ADMIN, self::TA, $school);

        $this->grant(self::TA, 'lecteur', $school, self::READ);
        self::assertSame(200, $this->call('GET', '/secondary/catalogue/streams', null, 'lecteur', self::TA, $school)[0]);
        [$status, $problem] = $this->call('POST', '/secondary/catalogue/streams', [], 'lecteur', self::TA, $school);
        self::assertSame(403, $status);
        self::assertArrayNotHasKey('errors', $problem, 'Le droit est contrôlé avant la validation.');
        $this->grant(self::TA, 'censeur-niveau', $school, self::MANAGE, 'structure', 'niveau-fictif');
        self::assertSame(403, $this->call('PATCH', '/secondary/catalogue/streams/' . $created['id'], ['hidden' => true, 'reason' => 'x'], 'censeur-niveau', self::TA, $school)[0], 'Exige tout l\'établissement.');
        $this->access->grant('intrus', $school);
        self::assertSame(403, $this->call('GET', '/secondary/catalogue/streams', null, 'intrus', self::TA, $school)[0]);

        $other = $this->factory->establishment(self::TB, 'leg', 'e-leg-b');
        $this->grant(self::TB, 'admin-b', $other, self::MANAGE);
        $codes = array_column($this->call('GET', '/secondary/catalogue/streams?per_page=50', null, 'admin-b', self::TB, $other)[1]['data'], 'code');
        self::assertNotContains('XLOC', $codes, 'L\'ajout de A est invisible pour B.');
        self::assertSame(404, $this->call('GET', '/secondary/catalogue/streams/' . $created['id'], null, 'admin-b', self::TB, $other)[0]);
        self::assertSame(404, $this->call('PATCH', '/secondary/catalogue/streams/' . $created['id'], ['hidden' => true, 'reason' => 'x'], 'admin-b', self::TB, $other)[0]);
        self::assertContains($this->call('GET', '/secondary/catalogue/streams', null, 'admin-b', self::TB, $school)[0], [403, 404]);
    }

    public function testAdministrationDeLaPlateforme(): void
    {
        $this->grantPlatformAdmin();
        self::assertSame(403, $this->call('GET', '/platform/secondary/streams')[0], 'Un administrateur de client n\'administre pas la liste de la plateforme.');
        [$status, $page] = $this->call('GET', '/platform/secondary/streams?per_page=200', null, self::PLATFORM_ADMIN, 'platform');
        self::assertSame(200, $status);
        self::assertCount(107, $page['data']);

        $created = $this->ok('POST', '/platform/secondary/streams', ['code' => 'E', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'Mathématiques et techniques', 'source_ref' => 'Source fictive de test (arrêté à fournir)'], self::PLATFORM_ADMIN, 'platform');
        self::assertSame(['platform', 'platform', 'custom', 1], [$created['scope'], $created['origin'], $created['confirmation'], $created['version_no']]);
        self::assertSame(422, $this->call('POST', '/platform/secondary/streams', ['code' => 'E', 'cycle_code' => 'second', 'teaching_kind' => 'general', 'label_fr' => 'Doublon', 'source_ref' => 'x'], self::PLATFORM_ADMIN, 'platform')[0]);

        $d = (string) $this->pdo()->query("SELECT id FROM se_stream_refs WHERE code = 'D' AND tenant_id = '*'")->fetchColumn();
        $changed = $this->ok('PATCH', '/platform/secondary/streams/' . $d, ['label_fr' => 'Sciences de la vie et de la Terre, mathématiques', 'confirmation' => 'confirmed', 'reason' => 'Texte fourni par le client (fictif)'], self::PLATFORM_ADMIN, 'platform');
        self::assertSame(['confirmed', 2], [$changed['confirmation'], $changed['version_no']]);
        self::assertSame(['renamed', 'confirmation'], array_column($changed['history'], 'change_kind'));
        $withdrawn = $this->ok('PATCH', '/platform/secondary/streams/' . $d, ['hidden' => true, 'reason' => 'Retrait fictif'], self::PLATFORM_ADMIN, 'platform');
        self::assertTrue($withdrawn['hidden']);

        $leg = $this->factory->establishment(self::TA, 'leg');
        $this->grant(self::TA, self::ADMIN, $leg, self::READ);
        $codes = array_column($this->call('GET', '/secondary/catalogue/streams?per_page=50', null, self::ADMIN, self::TA, $leg)[1]['data'], 'code');
        self::assertContains('E', $codes, 'Ajout de la plateforme proposé aux établissements.');
        self::assertNotContains('D', $codes, 'Ligne retirée par la plateforme : plus proposée.');
        self::assertSame(404, $this->call('GET', '/secondary/catalogue/streams/' . $d, null, self::ADMIN, self::TA, $leg)[0]);

        $this->permissions->grant('platform', 'svc:robot', 'platform.access');
        $this->permissions->grant('platform', 'svc:robot', 'platform.applications.manage');
        try {
            $this->service(PlatformAction::class)->assertPlatformAdmin(new Principal('svc:robot', 'platform'));
            self::fail('Un compte de service administre la liste.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status, 'Jamais un compte de service.');
        }
        $this->permissions->grant('platform', 'demi-admin', 'platform.access');
        self::assertSame(403, $this->call('GET', '/platform/secondary/streams', null, 'demi-admin', 'platform')[0], 'platform.applications.manage exigée.');
    }

    private function streamId(string $school, string $code): string
    {
        $page = $this->call('GET', '/secondary/catalogue/streams?per_page=50&filter[code]=' . $code, null, self::ADMIN, self::TA, $school)[1];
        self::assertCount(1, $page['data']);

        return (string) $page['data'][0]['id'];
    }
}
