<?php

declare(strict_types=1);

namespace Tests\Feature\Portals;

use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;
use Tests\Support\Portals\TeacherDoubles;
use Tests\Support\Reporting\ReportingTestCase;

final class TeacherPortalTest extends ReportingTestCase
{
    private TeacherDoubles $doubles;

    protected function setUp(): void
    {
        $this->doubles = new TeacherDoubles();
        $this->bootReporting([
            StaffDirectory::class => $this->doubles,
            TeacherWorkload::class => $this->doubles,
            'portals.grading_todo.test' => [$this->doubles],
            'portals.grading_todo.assessment' => [], // le fournisseur réel est testé dans Assessment
        ]);
        $this->actors->link('t1', 'prof', 'tp1');
        $this->actors->link('t1', 'sansfiche', 'p-quelconque');
        $this->actors->link('t2', 'autreprof', 'tp1');
        $this->doubles->staff['t1|tp1'] = new StaffInfo('tp1', 't1', 'permanent', null, 'active');
        $this->doubles->assignments['t1|tp1'] = [
            ['establishment_id' => 'e1', 'structure_id' => 'c1', 'subject_label' => 'Maths', 'session_kind' => 'cm'],
            ['establishment_id' => 'e2', 'structure_id' => 'c9', 'subject_label' => 'Physique', 'session_kind' => 'td'],
        ];
        $this->doubles->hours['t1|e1|tp1'] = ['declared_minutes' => 60, 'validated_minutes' => 240, 'rejected_minutes' => 0];
        $this->doubles->hours['t1|e2|tp1'] = ['declared_minutes' => 0, 'validated_minutes' => 120, 'rejected_minutes' => 30];
        $this->timetable->add(new SlotInfo('s2', 't1', 'e1', 'c1', 'tp1', 'Maths B', 4, '10:00', '11:00', '2026-09-01'));
        $this->timetable->add(new SlotInfo('s1', 't1', 'e1', 'c1', 'tp1', 'Maths A', 4, '08:00', '09:00', '2026-09-01'));
        $this->timetable->add(new SlotInfo('s3', 't1', 'e1', 'c2', 'autre', 'Latin', 4, '08:00', '09:00', '2026-09-01'));
    }

    public function testClassesEmploiDuTempsEtHeuresDuMois(): void
    {
        [$response, $json] = $this->api('/portal/teacher', 'prof');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $json['data'];

        self::assertSame('2026-10-08', $data['today']);
        self::assertSame(['Maths', 'Physique'], array_column($data['classes'], 'subject_label'));
        self::assertSame('IPES Douala', $data['classes'][0]['establishment_name']);
        self::assertSame(['Maths A', 'Maths B'], array_column($data['timetable_today'], 'subject'));
        self::assertSame(['month' => '2026-10', 'declared_minutes' => 60, 'validated_minutes' => 360, 'rejected_minutes' => 30], $data['hours_month']);
    }

    public function testSaisiesDeNotesNonEvaluablesSansSourceSinonListees(): void
    {
        $data = $this->api('/portal/teacher', 'prof')[1]['data'];
        self::assertFalse($data['grading_todo']['available']);
        self::assertSame([], $data['grading_todo']['items']);

        $this->doubles->todo = ['t1|tp1' => [['assessment_id' => 'a1', 'label' => 'Devoir 1', 'establishment_id' => 'e1', 'structure_id' => 'c1', 'missing_grades' => 7]]];
        $data = $this->api('/portal/teacher', 'prof')[1]['data'];
        self::assertTrue($data['grading_todo']['available']);
        self::assertSame(7, $data['grading_todo']['items'][0]['missing_grades']);
    }

    public function testCompteSansPersonneOuSansFicheEnseignantRefuse(): void
    {
        self::assertSame(403, $this->api('/portal/teacher', 'inconnu')[0]->getStatusCode());
        self::assertSame(403, $this->api('/portal/teacher', 'sansfiche')[0]->getStatusCode());
        self::assertSame(401, $this->kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/portal/teacher'))->getStatusCode());
    }

    public function testAucuneDonneeDUnAutreClient(): void
    {
        // « autreprof » (client t2) est lié à la même personne « tp1 », sans fiche dans t2
        self::assertSame(403, $this->api('/portal/teacher', 'autreprof', 't2')[0]->getStatusCode());
    }
}
