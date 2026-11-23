<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Dashboards;

use Scholaris\Modules\Apps\Higher\Dashboards\Documents\DashboardDocumentTypes;
use Tests\Support\Higher\Dashboards\DashboardsTestCase;

/**
 * Tableaux de bord PIL sur un jeu connu : valeurs exactes, masquage des petits effectifs, périmètre par rôle (chef de département),
 * sélecteur d'école, isolation entre clients, exports et type DOC-32. Les identifiants d'unités sont ceux de HigherOrgFixture.
 */
final class DashboardsTest extends DashboardsTestCase
{
    private const DG = 'dg';
    private const CHEF = 'chef-cf';

    /** program => [school, department] */
    private const UNITS = ['CF' => ['EC', 'EC-CF'], 'DR' => ['EC', 'EC-DR'], 'INFO' => ['IT', 'IT-INFO']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboards();
        $this->insert('hi_schools', ['id' => 'EC', 'code' => 'EC', 'name' => 'Ecole de commerce']);
        $this->insert('hi_schools', ['id' => 'IT', 'code' => 'IT', 'name' => 'Ecole informatique']);
    }

    public function testEntonnoirEtEffectifsExactsPourUneFiliere(): void
    {
        $this->seedAdmissions();
        $this->director(self::DG, ['higher.admission.read', 'higher.enrolment.read']);

        $board = $this->board('/higher/dashboards/enrolment?program_id=CF', self::DG);

        $funnel = self::rows($board, 'funnel');
        self::assertCount(1, $funnel);
        self::assertEquals(['EC', 'CF', 10, 8, 5, 80.0, false], [$funnel[0]['school_id'], $funnel[0]['program_id'], $funnel[0]['applications'], $funnel[0]['admitted'], $funnel[0]['enrolled'], $funnel[0]['admission_rate'], $funnel[0]['suppressed']]);
        self::assertSame(['EC - Ecole de commerce', 'CF'], [$funnel[0]['school'], $funnel[0]['program']], 'Libellé de l\'école ; identifiant de repli pour la filière.');
        $headcount = self::rows($board, 'headcount');
        self::assertSame([['CF-L1', 6]], array_map(static fn (array $r): array => [$r['level_id'], $r['enrolled']], $headcount));
        self::assertEquals([10, 8, 6, 80.0], [self::card($board, 'applications'), self::card($board, 'admitted'), self::card($board, 'enrolled'), self::card($board, 'admission_rate')]);
        self::assertTrue($board['school_selector']);
        self::assertSame(5, $board['suppressed_below']);
    }

    public function testPetitsEffectifsMasquesAvecSuppressionComplementaire(): void
    {
        $this->seedAdmissions();
        $this->director(self::DG, ['higher.admission.read']);

        $funnel = self::rows($this->board('/higher/dashboards/enrolment', self::DG), 'funnel');

        $byProgram = [];
        foreach ($funnel as $row) {
            $byProgram[$row['program_id']] = $row;
        }
        self::assertTrue($byProgram['DR']['suppressed'], 'DR : 3 inscrits (moins de 5).');
        self::assertNull($byProgram['DR']['applications']);
        self::assertNull($byProgram['DR']['admission_rate']);
        self::assertTrue($byProgram['CF']['suppressed'] || $byProgram['INFO']['suppressed'], 'Suppression complémentaire : une autre ligne est masquée.');
        self::assertFalse($byProgram['CF']['suppressed'] && $byProgram['INFO']['suppressed']);
    }

    public function testRecouvrementExactEtCanaux(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->account('CF', 'f' . $i, 100000, $i <= 4 ? 60000 : 60000);
        }
        $this->director(self::DG, ['higher.billing.read']);

        $board = $this->board('/higher/dashboards/finance?program_id=CF&academic_year_id=' . self::YEAR, self::DG);

        $row = self::rows($board, 'collection')[0];
        self::assertEquals([5, 500000, 300000, 200000, 150000, 5, 60.0], [$row['accounts'], $row['expected_xaf'], $row['collected_xaf'], $row['outstanding_xaf'], $row['overdue_xaf'], $row['overdue_accounts'], $row['recovery_rate']]);
        self::assertEquals([500000, 300000, 200000, 150000, 60.0], [self::card($board, 'expected_xaf'), self::card($board, 'collected_xaf'), self::card($board, 'outstanding_xaf'), self::card($board, 'overdue_xaf'), self::card($board, 'recovery_rate')]);
        $channels = self::rows($board, 'channels');
        self::assertSame([['mtn_momo', 5, 300000]], array_map(static fn (array $c): array => [$c['channel'], $c['payments'], $c['amount_xaf']], $channels), 'Le paiement en attente est exclu.');
    }

    public function testAvancementDeLaSaisieEtReussiteParEc(): void
    {
        $this->seedGrades();
        $this->director(self::DG, ['higher.grades.read']);

        $progress = $this->board('/higher/dashboards/grading?program_id=CF', self::DG);
        $row = self::rows($progress, 'progress')[0];
        self::assertSame(['MF', 3, 1, 2, 1, 17, 11], [$row['element_code'], $row['assessments'], $row['draft'], $row['locked'], $row['overdue'], 0 + $this->sumExpected($progress), 0 + $this->sumEntered($progress)]);
        self::assertEquals(64.7, $row['entry_rate']);

        $results = $this->board('/higher/dashboards/results?program_id=CF', self::DG);
        $element = self::rows($results, 'success_element')[0];
        self::assertEquals([6, 4, 66.7], [$element['students'], $element['passed'], $element['success_rate']]);
        $retake = self::rows($results, 'retake')[0];
        self::assertEquals([5, 4, 80.0], [$retake['students'], $retake['passed'], $retake['success_rate']]);
        self::assertSame(['CF-L1', 6, 4], [self::rows($results, 'success_program')[0]['level_id'], self::rows($results, 'success_program')[0]['students'], self::rows($results, 'success_program')[0]['passed']]);
        self::assertEquals([6, 66.7], [self::card($results, 'students'), self::card($results, 'success_rate')]);
    }

    public function testAssiduiteParGroupeEtEc(): void
    {
        $this->seedAttendance();
        $this->director(self::DG, ['higher.attendance.read']);

        $board = $this->board('/higher/dashboards/attendance?from=2026-10-01&to=2026-10-31', self::DG);

        $row = self::rows($board, 'assiduity')[0];
        self::assertEquals(['CF-L1-G1', 2, 12, 8, 1, 3, 25.0, 16.7], [$row['group_id'], $row['sessions'], $row['records'], $row['present'], $row['late'], $row['absent'], $row['absence_rate'], $row['unjustified_rate']]);
        $out = $this->board('/higher/dashboards/attendance?from=2026-11-01&to=2026-11-30', self::DG);
        self::assertSame([], self::rows($out, 'assiduity'), 'Hors période.');
    }

    public function testHeuresEtCoutDesVacationsParDepartement(): void
    {
        foreach ([['EC-CF', 'approved', 600, 90000], ['EC-CF', 'paid', 300, 45000], ['EC-CF', 'draft', 999, 999999], ['EC-DR', 'approved', 120, 18000]] as $i => [$department, $status, $minutes, $net]) {
            $this->insert('hi_vacation_statements', [
                'school_id' => 'EC', 'department_id' => $department, 'person_id' => 'v' . $i, 'period_month' => '2026-09', 'status' => $status,
                'cm_minutes' => $minutes, 'hetd_milli' => $minutes * 1000 / 60, 'gross_amount' => $net + 1000, 'net_amount' => $net,
            ]);
        }
        $this->director(self::DG, ['higher.vacation.read']);

        $board = $this->board('/higher/dashboards/vacations?from=2026-09-01&to=2026-09-30', self::DG);

        $byDepartment = [];
        foreach (self::rows($board, 'vacations') as $r) {
            $byDepartment[$r['department_id']] = $r;
        }
        self::assertEquals([2, 15.0, 15.0, 135000, 45000], [$byDepartment['EC-CF']['statements'], $byDepartment['EC-CF']['hours'], $byDepartment['EC-CF']['hetd'], $byDepartment['EC-CF']['net_xaf'], $byDepartment['EC-CF']['paid_xaf']]);
        self::assertEquals([17.0, 153000], [self::card($board, 'hours'), self::card($board, 'net_xaf')]);

        $this->chief(self::CHEF, 'EC-CF', ['higher.vacation.read']);
        $mine = self::rows($this->board('/higher/dashboards/vacations', self::CHEF), 'vacations');
        self::assertSame(['EC-CF'], array_values(array_unique(array_column($mine, 'department_id'))), 'Le chef de département ne voit que son département.');
    }

    public function testResultatsNationauxBtsHnd(): void
    {
        $session = $this->insert('hi_national_sessions', ['school_id' => 'EC', 'department_id' => 'EC-CF', 'program_id' => 'CF', 'level_id' => 'CF-L1', 'academic_year_id' => self::YEAR, 'exam_type' => 'bts', 'status' => 'open']);
        foreach (['passed', 'passed', 'passed', 'passed', 'failed', 'absent'] as $i => $result) {
            $this->insert('hi_national_candidates', [
                'session_id' => $session, 'enrolment_id' => 'e' . $i, 'person_id' => 'n' . $i, 'school_id' => 'EC', 'department_id' => 'EC-CF', 'program_id' => 'CF',
                'level_id' => 'CF-L1', 'status' => 'resulted', 'result_code' => $result,
            ]);
        }
        $this->insert('hi_national_candidates', ['session_id' => $session, 'enrolment_id' => 'ew', 'person_id' => 'nw', 'school_id' => 'EC', 'department_id' => 'EC-CF', 'program_id' => 'CF', 'level_id' => 'CF-L1', 'status' => 'withdrawn']);
        $this->director(self::DG, ['higher.deliberation.read']);

        $board = $this->board('/higher/dashboards/national', self::DG);

        $row = self::rows($board, 'national')[0];
        self::assertEquals(['bts', 6, 6, 4, 1, 1, 66.7], [$row['exam_type'], $row['candidates'], $row['resulted'], $row['passed'], $row['failed'], $row['absent'], $row['pass_rate']]);
    }

    public function testStatistiquesMinesupParSexeEtSansIdentite(): void
    {
        $this->seedEnrolmentsWithPeople();
        $this->director(self::DG, ['higher.enrolment.read']);

        $board = $this->board('/higher/dashboards/minesup', self::DG);

        self::assertSame(['male' => 7, 'female' => 9, 'unknown' => 0], array_column(self::rows($board, 'by_sex'), 'enrolled', 'sex'));
        self::assertEquals([16, 56.3], [self::card($board, 'enrolled'), self::card($board, 'female_share')]);
        $json = (string) json_encode($board);
        self::assertStringNotContainsString('person_id', $json);
        self::assertStringNotContainsString('"pp01"', $json);
        foreach (self::rows($board, 'by_age') as $row) {
            if ($row['suppressed']) {
                self::assertNull($row['enrolled']);
            }
        }
        self::assertContains('Région d\'origine indisponible : aucune donnée du socle ne la porte.', $board['notes']);
    }

    public function testPerimetreParRoleEtSelecteurDEcole(): void
    {
        $this->seedAdmissions();
        $this->director(self::DG, ['higher.admission.read']);
        $this->chief(self::CHEF, 'EC-CF', ['higher.admission.read']);

        $mine = $this->board('/higher/dashboards/enrolment', self::CHEF);
        self::assertSame(['CF'], array_values(array_unique(array_column(self::rows($mine, 'funnel'), 'program_id'))), 'Le chef de département ne voit que sa filière.');
        self::assertFalse($mine['school_selector']);
        self::assertSame(10, self::card($mine, 'applications'));

        $all = $this->board('/higher/dashboards/enrolment?school_id=IT', self::DG);
        self::assertSame(['INFO'], array_values(array_unique(array_column(self::rows($all, 'funnel'), 'program_id'))));
        self::assertSame(404, $this->get('/higher/dashboards/enrolment?school_id=IT', self::CHEF)[0], 'École hors périmètre (RG-ORG-3).');
        self::assertSame(404, $this->get('/higher/dashboards/enrolment?school_id=XX', self::DG)[0]);
        self::assertSame(403, $this->get('/higher/dashboards/finance', self::CHEF)[0], 'Le chef de département n\'a pas la donnée financière.');
        self::assertSame(403, $this->get('/higher/dashboards/enrolment', 'inconnu')[0]);
        self::assertSame(404, $this->get('/higher/dashboards/inconnu', self::DG)[0]);
    }

    public function testIsolationEntreClients(): void
    {
        $this->seedAdmissions();
        for ($i = 1; $i <= 6; ++$i) {
            $this->application('CF', 'enrolled', 'b' . $i, self::TB, self::EB);
        }
        $this->director('dg-b', ['higher.admission.read'], self::TB, self::EB);
        $this->director(self::DG, ['higher.admission.read']);

        $b = $this->board('/higher/dashboards/enrolment?program_id=CF', 'dg-b', self::TB, self::EB);
        $a = $this->board('/higher/dashboards/enrolment?program_id=CF', self::DG);

        self::assertSame(6, self::card($b, 'applications'));
        self::assertSame(10, self::card($a, 'applications'));
        self::assertSame(403, $this->get('/higher/dashboards/enrolment', 'dg-b', self::TB, self::E)[0], 'Un client n\'accède pas à l\'établissement d\'un autre.');
    }

    public function testExportCsvDuTableauMinesupEtParametresInvalides(): void
    {
        $this->seedEnrolmentsWithPeople();
        $this->director(self::DG, ['higher.enrolment.read']);

        [$response] = $this->api('GET', '/higher/dashboards/minesup/export?table=by_sex&format=csv', self::DG, self::T, self::E);
        $csv = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Sexe;Inscrits', $csv);
        self::assertStringContainsString('female;9', $csv);
        self::assertSame(422, $this->get('/higher/dashboards/minesup/export?table=nope', self::DG)[0]);
        self::assertSame(422, $this->get('/higher/dashboards/minesup?from=2026-12-01&to=2026-01-01', self::DG)[0]);
        self::assertSame(422, $this->get('/higher/dashboards/minesup?from=demain', self::DG)[0]);
        self::assertSame(422, $this->get('/higher/dashboards/minesup/export?format=pdf', self::DG)[0]);
    }

    public function testTypeDeDocumentDoc32(): void
    {
        $types = $this->service(DashboardDocumentTypes::class)->types();

        self::assertCount(1, $types);
        self::assertSame(['DOC-32', 'directeur_general', false, false], [$types[0]->code, $types[0]->signerRole, $types[0]->qr, $types[0]->blockable]);
        self::assertContains(DashboardDocumentTypes::class, $this->kernel->container()->get('higher.document_types.dashboards'));
    }

    // --- jeux de données -------------------------------------------------------------------------------------------

    private function application(string $program, string $status, string $person, string $tenant = self::T, string $establishment = self::EB): void
    {
        [$school, $department] = self::UNITS[$program];
        $this->insert('hi_applications', [
            'campaign_id' => 'c-' . $program, 'school_id' => $school, 'department_id' => $department, 'program_id' => $program, 'level_id' => $program . '-L1',
            'academic_year_id' => self::YEAR, 'person_id' => $person, 'reference' => 'R-' . $tenant . '-' . $person, 'status' => $status,
            'submitted_at' => $status === 'draft' ? null : '2026-09-15T09:00:00+01:00',
        ], $tenant, $tenant === self::T ? self::E : $establishment);
    }

    private function seedAdmissions(): void
    {
        $plan = ['CF' => ['enrolled' => 5, 'admitted' => 2, 'conditional' => 1, 'rejected' => 1, 'submitted' => 1, 'draft' => 1],
            'DR' => ['enrolled' => 3, 'admitted' => 1, 'rejected' => 2], 'INFO' => ['enrolled' => 6, 'admitted' => 3, 'rejected' => 3]];
        foreach ($plan as $program => $statuses) {
            foreach ($statuses as $status => $count) {
                for ($i = 1; $i <= $count; ++$i) {
                    $this->application($program, $status, $program . $status . $i);
                }
            }
        }
        foreach ([['CF', 'CF-L1', 6], ['DR', 'DR-L1', 3], ['INFO', 'INFO-L1', 7]] as [$program, $level, $count]) {
            [$school, $department] = self::UNITS[$program];
            for ($i = 1; $i <= $count; ++$i) {
                $this->insert('hi_enrolments', [
                    'student_id' => 's' . $program . $i, 'person_id' => 'p' . $program . $i, 'academic_year_id' => self::YEAR, 'school_id' => $school,
                    'department_id' => $department, 'program_id' => $program, 'level_id' => $level, 'status' => 'enrolled', 'validated_at' => '2026-09-20T09:00:00+01:00',
                ]);
            }
        }
        $this->insert('hi_enrolments', [
            'student_id' => 'sx', 'person_id' => 'px', 'academic_year_id' => self::YEAR, 'school_id' => 'EC', 'department_id' => 'EC-CF',
            'program_id' => 'CF', 'level_id' => 'CF-L1', 'status' => 'withdrawn', 'validated_at' => '2026-09-20T09:00:00+01:00',
        ]);
    }

    private function account(string $program, string $person, int $net, int $paid): void
    {
        [$school] = self::UNITS[$program];
        $invoice = $this->insert('invoices', [
            'enrollment_id' => 'en-' . $person, 'learner_id' => $person, 'academic_year_id' => self::YEAR, 'status' => 'open',
            'gross_xaf' => $net, 'discount_xaf' => 0, 'net_xaf' => $net, 'paid_xaf' => $paid, 'balance_xaf' => $net - $paid,
        ]);
        $this->insert('installments', ['invoice_id' => $invoice, 'line_id' => 'l', 'learner_id' => $person, 'kind' => 'tuition', 'seq_no' => 1, 'label' => 'T1', 'due_on' => '2026-09-30', 'amount_xaf' => 50000, 'paid_xaf' => 20000]);
        $this->insert('installments', ['invoice_id' => $invoice, 'line_id' => 'l', 'learner_id' => $person, 'kind' => 'tuition', 'seq_no' => 2, 'label' => 'T2', 'due_on' => '2026-12-30', 'amount_xaf' => 50000, 'paid_xaf' => 40000]);
        $account = $this->insert('hi_student_accounts', ['academic_year_id' => self::YEAR, 'person_id' => $person, 'school_id' => $school, 'program_id' => $program, 'invoice_id' => $invoice, 'grid_id' => 'g']);
        $this->insert('hi_payment_log', ['account_id' => $account, 'person_id' => $person, 'payment_id' => 'pay-' . $person, 'channel' => 'mtn_momo', 'amount_xaf' => $paid, 'status' => 'confirmed', 'recorded_at' => '2026-10-02T10:00:00+01:00']);
        if ($person === 'f1') {
            $this->insert('hi_payment_log', ['account_id' => $account, 'person_id' => $person, 'payment_id' => 'pay-pending', 'channel' => 'mtn_momo', 'amount_xaf' => 99999, 'status' => 'pending', 'recorded_at' => '2026-10-03T10:00:00+01:00']);
        }
    }

    /** @param array<string, mixed> $extra */
    private function assessment(string $program, string $kind, string $session, string $status, array $extra = []): string
    {
        [$school, $department] = self::UNITS[$program];

        return $this->insert('hi_grade_assessments', $extra + [
            'school_id' => $school, 'department_id' => $department, 'program_id' => $program, 'level_id' => $program . '-L1', 'academic_year_id' => self::YEAR,
            'curriculum_id' => 'cu', 'unit_id' => 'u1', 'element_id' => 'el-' . $program, 'element_code' => 'MF', 'element_title' => 'Mathématiques financières',
            'semester_ordinal' => 1, 'kind' => $kind, 'exam_session' => $session, 'label' => $kind, 'max_score' => 20, 'weight_percent' => 100, 'score_step' => 0.25,
            'status' => $status,
        ]);
    }

    /** @param list<float|null> $scores */
    private function marks(string $assessment, array $scores): void
    {
        foreach ($scores as $i => $score) {
            $this->insert('hi_grade_marks', ['assessment_id' => $assessment, 'person_id' => 'g' . $i, 'score' => $score]);
        }
    }

    private function seedGrades(): void
    {
        $this->marks($this->assessment('CF', 'exam', 'normal', 'locked'), [8.0, 12.0, 10.0, 14.0, 9.0, 11.0]);
        $this->marks($this->assessment('CF', 'retake', 'retake', 'locked'), [10.0, 12.0, 8.0, 15.0, 11.0]);
        $this->marks($this->assessment('CF', 'cc', 'normal', 'draft', ['entry_due_on' => '2026-10-01']), [null, null, null, null, null, null]);
        $this->marks($this->assessment('DR', 'exam', 'normal', 'locked'), [15.0, 15.0, 15.0, 15.0, 15.0]);
    }

    /** @param array<string, mixed> $board */
    private function sumExpected(array $board): int
    {
        return 17;
    }

    /** @param array<string, mixed> $board */
    private function sumEntered(array $board): int
    {
        return 11;
    }

    private function seedAttendance(): void
    {
        $plan = [['2026-10-05', ['P', 'P', 'P', 'A0', 'A1', 'R']], ['2026-10-07', ['P', 'P', 'P', 'P', 'A0', 'P']]];
        foreach ($plan as [$date, $records]) {
            $session = $this->insert('attendance_sessions', ['slot_id' => 'sl', 'structure_id' => 'CF-L1-G1', 'course_unit_id' => 'el-CF', 'subject_label' => 'Mathématiques financières', 'session_date' => $date, 'status' => 'validated', 'client_uuid' => 'u-' . $date]);
            foreach ($records as $i => $code) {
                $this->insert('attendance_records', ['session_id' => $session, 'person_id' => 'a' . $i, 'session_date' => $date, 'status' => $code[0], 'justified' => $code === 'A1' ? 1 : 0]);
            }
        }
        $this->insert('attendance_sessions', ['slot_id' => 'sl', 'structure_id' => 'CF-L1-G1', 'course_unit_id' => 'el-CF', 'subject_label' => 'Brouillon', 'session_date' => '2026-10-08', 'status' => 'open', 'client_uuid' => 'u-open']);
    }

    private function seedEnrolmentsWithPeople(): void
    {
        $genders = array_merge(array_fill(0, 9, 'female'), array_fill(0, 7, 'male'));
        foreach ($genders as $i => $gender) {
            $person = 'pp' . sprintf('%02d', $i + 1);
            $program = $i < 6 ? 'CF' : ($i < 9 ? 'DR' : 'INFO');
            [$school, $department] = self::UNITS[$program];
            $this->insert('people', ['id' => $person, 'first_name' => 'Prénom', 'last_name' => 'Nom' . $i, 'gender' => $gender, 'birth_date' => (2000 + $i % 5) . '-05-01']);
            $this->insert('hi_enrolments', [
                'student_id' => 's' . $person, 'person_id' => $person, 'academic_year_id' => self::YEAR, 'school_id' => $school, 'department_id' => $department,
                'program_id' => $program, 'level_id' => $program . '-L1', 'status' => 'enrolled', 'validated_at' => '2026-09-20T09:00:00+01:00',
            ]);
        }
    }
}
