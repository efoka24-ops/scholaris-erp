<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Development;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Apps\Nursery\Development\Application\MediaRetention;
use Scholaris\Modules\Apps\Nursery\Development\Console\PurgeMediaCommand;

/**
 * MQ-06, MQ-07 : consentement photo recueilli (formulaire ou portail), révocable, historisé ; aucune photo servie sans consentement
 * actif ; lecture des photos journalisée ; conservation paramétrée et purge planifiée. Images fictives (octets générés).
 */
final class PhotoConsentTest extends DevelopmentTestCase
{
    private string $parent;

    protected function setUp(): void
    {
        $this->bootDevelopment();
        $this->parent = 'parent-' . $this->ada['child'];
        $this->grant(self::TA, 'secretaire-a', self::EA, 'nursery.consent.manage');
    }

    private function photoPublished(string $activityOn = '2026-10-20'): string
    {
        $entry = $this->ok('POST', '/nursery/lifebook/entries', ['section_id' => $this->sectionA, 'title' => 'Atelier peinture', 'activity_on' => $activityOn], 'educatrice-a');
        $with = $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/media', ['content_base64' => base64_encode('image-generee-' . $activityOn), 'children' => [$this->ada['child']]], 'educatrice-a');
        $this->ok('POST', '/nursery/lifebook/entries/' . $entry['id'] . '/publish', [], 'educatrice-a');

        return (string) end($with['media'])['id'];
    }

    public function testFormulaireSigneRevocationEtHistorique(): void
    {
        $path = '/nursery/children/' . $this->ada['child'] . '/photo-consent';
        self::assertFalse($this->ok('GET', $path)['active']);
        self::assertSame(422, $this->code('POST', $path, ['signed_on' => '2026-10-01', 'signed_by_name' => 'Parent fictif'], 'secretaire-a'), 'La référence du formulaire est obligatoire.');
        $granted = $this->ok('POST', $path, ['evidence_ref' => 'FORM-PHOTO-0001', 'signed_on' => '2026-10-01', 'signed_by_name' => 'Parent fictif'], 'secretaire-a');
        self::assertTrue($granted['active']);
        self::assertSame(409, $this->code('POST', $path, ['evidence_ref' => 'FORM-PHOTO-0002', 'signed_on' => '2026-10-02', 'signed_by_name' => 'Parent fictif'], 'secretaire-a'));
        $revoked = $this->ok('POST', $path . '/revoke', ['reason' => 'Demande écrite du parent (fictive).'], 'secretaire-a');
        self::assertFalse($revoked['active']);
        self::assertSame('revoked', $revoked['history'][0]['status']);
        self::assertSame('FORM-PHOTO-0001', $revoked['history'][0]['evidence_ref']);
        self::assertSame(403, $this->code('POST', $path, ['evidence_ref' => 'X', 'signed_on' => '2026-10-01', 'signed_by_name' => 'X'], 'educatrice-a'), 'L’éducatrice ne recueille pas le consentement.');
    }

    public function testLeParentDonneEtRetireSonConsentementLaPhotoDisparaitAussitot(): void
    {
        $media = $this->photoPublished();
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent), 'Sans consentement actif : jamais servie.');
        self::assertSame(422, $this->code('POST', '/nursery/portal/photo-consent', ['learner_id' => $this->ada['child'], 'signed_by_name' => 'Parent fictif', 'accept' => false], $this->parent));
        $this->ok('POST', '/nursery/portal/photo-consent', ['learner_id' => $this->ada['child'], 'signed_by_name' => 'Parent fictif', 'accept' => true], $this->parent);
        self::assertSame(200, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent));
        self::assertContains('nursery.lifebook.media_read', $this->audit->actions(), 'Toute lecture de photo est journalisée.');
        $state = $this->ok('GET', '/nursery/portal/photo-consent?learner_id=' . $this->ada['child'], null, $this->parent);
        self::assertSame('portal', $state['history'][0]['channel']);
        self::assertSame('2026-1', $state['history'][0]['statement_version']);
        $this->ok('POST', '/nursery/portal/photo-consent/revoke', ['learner_id' => $this->ada['child']], $this->parent);
        self::assertSame(404, $this->code('GET', '/nursery/portal/lifebook/media/' . $media, null, $this->parent), 'Retrait : la photo disparaît à la lecture suivante.');
        self::assertSame(404, $this->code('POST', '/nursery/portal/photo-consent', ['learner_id' => $this->ada['child'], 'signed_by_name' => 'X', 'accept' => true], 'parent-' . $this->bao['child']), 'Pas pour l’enfant d’un autre.');
    }

    public function testLaPurgeSupprimeLesPhotosEchuesSeulementEtLaisseUneTrace(): void
    {
        $old = $this->photoPublished('2025-09-15');
        $recent = $this->photoPublished('2026-10-20');
        $this->setSetting(self::TA, self::EA, 'nursery.lifebook.retention_days', 365);
        $result = $this->service(MediaRetention::class)->purge(self::TA, self::EA, '2026-10-21');
        self::assertSame(1, $result['purged']);
        $statement = $this->service(ConnectionFactory::class)->pdo()->prepare('SELECT id, status, purged_at FROM nu_lifebook_media ORDER BY id');
        $statement->execute();
        $rows = array_column($statement->fetchAll(\PDO::FETCH_ASSOC), null, 'id');
        self::assertSame('purged', $rows[$old]['status']);
        self::assertNotNull($rows[$old]['purged_at']);
        self::assertNotSame('purged', $rows[$recent]['status']);
        self::assertSame(404, $this->code('GET', '/nursery/lifebook/media/' . $old, null, 'educatrice-a'), 'Une photo purgée n’existe plus.');
        self::assertSame(0, $this->service(MediaRetention::class)->purge(self::TA, self::EA, '2026-10-21')['purged'], 'Idempotente.');

        $this->service(ConnectionFactory::class)->pdo()->exec("UPDATE settings SET value_json = 'null' WHERE setting_key = 'nursery.lifebook.retention_days' AND establishment_id = '" . self::EA . "'");
        self::assertSame(0, $this->service(MediaRetention::class)->purge(self::TA, self::EA, '2030-01-01')['purged'], 'Sans durée : rien n’est purgé.');
        ob_start();
        self::assertSame(0, $this->service(PurgeMediaCommand::class)->run(['2026-10-21']));
        ob_end_clean();
    }
}
