<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumCatalog;
use Scholaris\Modules\Socle\Assessment\Contracts\CurriculumItem;
use Tests\Support\Assessment\AssessmentTestCase;

/** D9 : catalogue de programme, upsert idempotent par clé externe, désactivation, lecture, isolation. */
final class CurriculumCatalogTest extends AssessmentTestCase
{
    private CurriculumCatalog $catalog;

    protected function setUp(): void
    {
        $this->bootAssessment();
        $this->catalog = $this->container()->get(CurriculumCatalog::class);
    }

    public function testUpsertIdempotentPuisMiseAJour(): void
    {
        $ue = new CurriculumItem('hi_unit:UE1', 'UE1-CF', 'Comptabilité', 'fundamental', 1.0, 6);
        $id = $this->catalog->upsertSubject('t1', 'e1', $ue);
        self::assertSame($id, $this->catalog->upsertSubject('t1', 'e1', $ue), 'Rejouer ne crée rien.');
        self::assertCount(1, $this->rows('SELECT id FROM subjects'));

        $this->catalog->upsertSubject('t1', 'e1', new CurriculumItem('hi_unit:UE1', 'UE1-CF', 'Comptabilité générale', 'fundamental', 1.0, 6));
        $entry = $this->catalog->find('t1', 'e1', 'hi_unit:UE1');
        self::assertNotNull($entry);
        self::assertSame('Comptabilité générale', $entry->name);
        self::assertSame('fundamental', $entry->kind);
        self::assertSame(6, $entry->credits);

        [, $json] = $this->api('GET', '/subjects/' . $id, 'reader');
        self::assertSame('hi_unit:UE1', $json['data']['external_key']);
    }

    public function testElementsDesactivationEtReactivation(): void
    {
        $subject = $this->catalog->upsertSubject('t1', 'e1', new CurriculumItem('UE1', 'UE1', 'Comptabilité', 'fundamental', 1.0, 6));
        $ec = $this->catalog->upsertElement('t1', 'e1', 'UE1', new CurriculumItem('EC1', 'EC1', 'Comptabilité générale', 'element', 1.0, 4));
        self::assertSame($ec, $this->catalog->upsertElement('t1', 'e1', 'UE1', new CurriculumItem('EC1', 'EC1', 'Comptabilité générale', 'element', 1.0, 4)));
        $this->catalog->upsertElement('t1', 'e1', 'UE1', new CurriculumItem('EC2', 'EC2', 'TD de comptabilité', 'element', 1.0, 2));
        self::assertSame([4, 2], array_map(static fn ($e): int => $e->credits, $this->catalog->elementsOf('t1', 'e1', 'UE1')));

        self::assertTrue($this->catalog->deactivate('t1', 'e1', 'EC1'));
        self::assertFalse($this->catalog->find('t1', 'e1', 'EC1')?->active ?? true);
        [$refused] = $this->api('POST', '/assessments', 'censor', [
            'subject_id' => $subject, 'element_id' => $ec, 'structure_id' => 'c1', 'period_id' => 'S1', 'kind' => 'cc', 'title' => 'CC',
        ]);
        self::assertSame(422, $refused->getStatusCode(), 'Un élément désactivé ne reçoit plus d\'épreuve.');

        $this->catalog->upsertElement('t1', 'e1', 'UE1', new CurriculumItem('EC1', 'EC1', 'Comptabilité générale', 'element', 1.0, 4));
        self::assertTrue($this->catalog->find('t1', 'e1', 'EC1')?->active);

        self::assertTrue($this->catalog->deactivate('t1', 'e1', 'UE1'));
        self::assertFalse($this->catalog->find('t1', 'e1', 'UE1')?->active ?? true);
        self::assertFalse($this->catalog->deactivate('t1', 'e1', 'inconnu'));
    }

    public function testCodeDejaPrisEtParentManquant(): void
    {
        $this->subject('UE1');
        try {
            $this->catalog->upsertSubject('t1', 'e1', new CurriculumItem('UE1', 'UE1', 'Autre'));
            self::fail('Code déjà pris : 409 attendu.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $this->catalog->upsertElement('t1', 'e1', 'absente', new CurriculumItem('EC9', 'EC9', 'EC'));
            self::fail('Matière parente absente : 404 attendu.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testIsolationEntreClientsEtEtablissements(): void
    {
        $this->catalog->upsertSubject('t1', 'e1', new CurriculumItem('UE1', 'UE1', 'Comptabilité'));

        self::assertNull($this->catalog->find('t2', 'e1', 'UE1'));
        self::assertNull($this->catalog->find('t1', 'e2', 'UE1'));
        $other = $this->catalog->upsertSubject('t2', 'e1', new CurriculumItem('UE1', 'UE1', 'Comptabilité'));
        self::assertNotSame($this->catalog->find('t1', 'e1', 'UE1')?->id, $other);
    }
}
