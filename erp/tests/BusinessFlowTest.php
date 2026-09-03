<?php

declare(strict_types=1);

namespace Scholaris\Tests;

use Scholaris\Database\Table;
use Scholaris\Service\Billing;
use Scholaris\Service\GradeCalculator;

/**
 * Chaine metier complete : eleve -> classe -> inscription -> facture ->
 * encaissement, puis notes -> moyennes -> classement.
 *
 * C'est le parcours qui porte l'argent et les resultats : ses invariants
 * (solde juste, pas de double encaissement, classement coherent) meritent
 * d'etre verifies plutot que constates en production.
 */
final class BusinessFlowTest extends TestCase
{
    private string $academicYearId;

    private string $levelId;

    private string $classroomId;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->tenant()->set($this->tenantA);

        $this->userId = $this->createUser($this->tenantA, 'intendant@a.cm');
        $this->giveRole($this->userId, 'INTENDANT', [
            'students:read', 'students:create', 'enrollments:read', 'enrollments:create',
            'invoices:read', 'payments:create', 'payments:read', 'finance-dashboard:read',
            'classrooms:read', 'fee-structures:read', 'fee-structures:create',
            'grades:read', 'grades:create', 'grades:calculate', 'grades:publish',
        ]);

        $this->academicYearId = $this->createAcademicYear();
        $this->levelId = $this->createLevel();
        $this->classroomId = $this->createClassroom();

        $this->actingAs($this->userId);
    }

    // --- Facturation ---------------------------------------------------------

    public function testUneInscriptionGenereLaFactureDuNiveau(): void
    {
        $this->createFeeStructure(75000.0);
        $studentId = $this->createStudent($this->tenantA, 'A/001');

        $response = $this->request('POST', '/enrollments', [
            'student_id' => $studentId,
            'classroom_id' => $this->classroomId,
            'type' => 'NEW',
            'regime' => 'EXTERNAL',
        ]);

        $this->assertSame(302, $response->status(), 'L inscription doit rediriger');

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE student_id = :id', ['id' => $studentId]);

        $this->assertTrue($invoice !== null, 'Une facture doit avoir ete generee');
        $this->assertMoney(75000.0, $invoice['total_amount'], 'Le montant doit venir de la grille');
        $this->assertMoney(75000.0, $invoice['balance'], 'Le solde initial egale le total');
        $this->assertSame('PENDING', (string) $invoice['status'], 'La facture part en attente');
    }

    public function testUneInscriptionSansGrilleNEchouePas(): void
    {
        // Sans grille tarifaire, l'inscription doit rester possible : un
        // etablissement parametre parfois ses tarifs apres la rentree.
        $studentId = $this->createStudent($this->tenantA, 'A/002');

        $this->request('POST', '/enrollments', [
            'student_id' => $studentId,
            'classroom_id' => $this->classroomId,
            'type' => 'NEW',
            'regime' => 'EXTERNAL',
        ]);

        $enrollments = (int) $this->db->scalar('SELECT COUNT(*) FROM enrollments');
        $invoices = (int) $this->db->scalar('SELECT COUNT(*) FROM invoices');

        $this->assertSame(1, $enrollments, 'L inscription doit exister');
        $this->assertSame(0, $invoices, 'Aucune facture sans grille tarifaire');
    }

    public function testUnPaiementMetAJourLeSoldeEtLeStatut(): void
    {
        $invoiceId = $this->enrolAndInvoice('A/003', 100000.0);
        $billing = new Billing($this->db, $this->app->tenant());

        $billing->recordPayment($invoiceId, 40000.0, 'CASH', null, $this->userId, null);

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = :id', ['id' => $invoiceId]);
        $this->assertMoney(40000.0, $invoice['paid_amount'], 'Le regle doit refleter le paiement');
        $this->assertMoney(60000.0, $invoice['balance'], 'Le solde doit diminuer d autant');
        $this->assertSame('PARTIAL', (string) $invoice['status'], 'La facture devient partielle');

        $billing->recordPayment($invoiceId, 60000.0, 'MOBILE_MONEY', null, $this->userId, null);

        $invoice = $this->db->selectOne('SELECT * FROM invoices WHERE id = :id', ['id' => $invoiceId]);
        $this->assertMoney(0.0, $invoice['balance'], 'Le solde doit etre nul');
        $this->assertSame('PAID', (string) $invoice['status'], 'La facture doit etre soldee');
    }

    public function testUnPaiementSuperieurAuSoldeEstRefuse(): void
    {
        $invoiceId = $this->enrolAndInvoice('A/004', 50000.0);
        $billing = new Billing($this->db, $this->app->tenant());

        // Depasser le solde est presque toujours une faute de frappe : mieux
        // vaut refuser que creer un avoir implicite.
        $this->assertThrows(
            fn () => $billing->recordPayment($invoiceId, 60000.0, 'CASH', null, $this->userId, null),
            'Un encaissement superieur au solde doit etre refuse'
        );

        $invoice = $this->db->selectOne('SELECT paid_amount FROM invoices WHERE id = :id', ['id' => $invoiceId]);
        $this->assertMoney(0.0, $invoice['paid_amount'], 'Rien ne doit avoir ete impute');
    }

    public function testLesNumerosDeRecuSeSuivent(): void
    {
        $first = $this->enrolAndInvoice('A/005', 20000.0);
        $second = $this->enrolAndInvoice('A/006', 20000.0);

        $billing = new Billing($this->db, $this->app->tenant());
        $a = $billing->recordPayment($first, 5000.0, 'CASH', null, $this->userId, null);
        $b = $billing->recordPayment($second, 5000.0, 'CASH', null, $this->userId, null);

        $year = date('Y');
        $this->assertSame("AAA/{$year}/000001", $a['receipt_number'], 'Premier recu');
        $this->assertSame("AAA/{$year}/000002", $b['receipt_number'], 'Second recu, numero suivant');
    }

    public function testLeSoldeResteJusteApresPlusieursPaiements(): void
    {
        $invoiceId = $this->enrolAndInvoice('A/007', 100000.0);
        $billing = new Billing($this->db, $this->app->tenant());

        // Montants a decimales : additionner des flottants ferait deriver le
        // solde de quelques centimes, ce que DECIMAL doit eviter.
        foreach ([33333.33, 33333.33, 33333.34] as $amount) {
            $billing->recordPayment($invoiceId, $amount, 'CASH', null, $this->userId, null);
        }

        $invoice = $this->db->selectOne('SELECT balance, status FROM invoices WHERE id = :id', ['id' => $invoiceId]);

        $this->assertMoney(0.0, $invoice['balance'], 'Le solde doit tomber exactement a zero');
        $this->assertSame('PAID', (string) $invoice['status'], 'La facture doit etre soldee');
    }

    // --- Notes et moyennes ---------------------------------------------------

    public function testLeCalculProduitMoyennesEtClassement(): void
    {
        $subjectId = $this->createSubject('MATH', 'Mathematiques', 4);
        $periodId = $this->createPeriod();

        $first = $this->enrolStudent('A/010', 'PREMIER');
        $second = $this->enrolStudent('A/011', 'SECOND');

        $this->addGrade($first, $subjectId, $periodId, 16.0);
        $this->addGrade($second, $subjectId, $periodId, 8.0);

        $calculator = new GradeCalculator($this->db, $this->app->tenant());
        $result = $calculator->calculate($this->classroomId, $periodId);

        $this->assertSame(2, $result['students'], 'Deux eleves doivent etre traites');

        $rows = $this->db->select(
            'SELECT student_id, general_average, rank_position, mention FROM period_results ORDER BY rank_position'
        );

        $this->assertCount(2, $rows, 'Deux resultats doivent exister');
        $this->assertSame($first, (string) $rows[0]['student_id'], 'Le meilleur doit etre premier');
        $this->assertSame(1, (int) $rows[0]['rank_position'], 'Rang 1');
        $this->assertMoney(16.0, $rows[0]['general_average'], 'Moyenne du premier');
        $this->assertSame('Tres bien', (string) $rows[0]['mention'], 'Mention correspondante');
        $this->assertSame(2, (int) $rows[1]['rank_position'], 'Rang 2');
    }

    public function testUneAbsenceNonJustifieeCompteZero(): void
    {
        $subjectId = $this->createSubject('FRAN', 'Francais', 3);
        $periodId = $this->createPeriod();
        $studentId = $this->enrolStudent('A/020', 'ABSENT');

        $this->addGrade($studentId, $subjectId, $periodId, 20.0);
        $this->addGrade($studentId, $subjectId, $periodId, null, true, false);

        $calculator = new GradeCalculator($this->db, $this->app->tenant());
        $calculator->calculate($this->classroomId, $periodId);

        $row = $this->db->selectOne('SELECT general_average FROM period_results WHERE student_id = :id', ['id' => $studentId]);

        // 20 et 0, ponderations egales : la moyenne doit etre 10.
        $this->assertMoney(10.0, $row['general_average'], 'Une absence non justifiee compte zero');
    }

    public function testUneAbsenceJustifieeEstIgnoree(): void
    {
        $subjectId = $this->createSubject('SVT', 'Sciences', 2);
        $periodId = $this->createPeriod();
        $studentId = $this->enrolStudent('A/021', 'MALADE');

        $this->addGrade($studentId, $subjectId, $periodId, 14.0);
        $this->addGrade($studentId, $subjectId, $periodId, null, true, true);

        $calculator = new GradeCalculator($this->db, $this->app->tenant());
        $calculator->calculate($this->classroomId, $periodId);

        $row = $this->db->selectOne('SELECT general_average FROM period_results WHERE student_id = :id', ['id' => $studentId]);

        // Un eleve malade ne doit pas etre penalise : seule la note obtenue compte.
        $this->assertMoney(14.0, $row['general_average'], 'Une absence justifiee est ignoree');
    }

    public function testLeCalculEstRejouableSansDoublon(): void
    {
        $subjectId = $this->createSubject('HIST', 'Histoire', 2);
        $periodId = $this->createPeriod();
        $studentId = $this->enrolStudent('A/030', 'REJOUE');
        $this->addGrade($studentId, $subjectId, $periodId, 12.0);

        $calculator = new GradeCalculator($this->db, $this->app->tenant());
        $calculator->calculate($this->classroomId, $periodId);
        $calculator->calculate($this->classroomId, $periodId);

        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM period_results'),
            'Un recalcul ne doit pas dupliquer les resultats'
        );
        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM grade_calculations'),
            'Ni les moyennes par matiere'
        );
    }

    // --- Outillage -----------------------------------------------------------

    private function createAcademicYear(): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $id,
                'tenant' => $this->tenantA,
                'label' => '2026-2027',
                'start' => '2026-09-01',
                'end' => '2027-06-30',
                'status' => 'ACTIVE',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    private function createPeriod(): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
             VALUES (:id, :year, :type, :number, :start, :end, :status)',
            [
                'id' => $id,
                'year' => $this->academicYearId,
                'type' => 'SEQUENCE',
                'number' => 1,
                'start' => '2026-09-01',
                'end' => '2026-10-15',
                'status' => 'OPEN',
            ]
        );

        return $id;
    }

    private function createLevel(): string
    {
        $cycleId = Table::uuid();

        $this->db->execute(
            'INSERT INTO cycles (id, tenant_id, code, name, sort_order, created_at)
             VALUES (:id, :tenant, :code, :name, :sort, :created_at)',
            [
                'id' => $cycleId,
                'tenant' => $this->tenantA,
                'code' => 'COLLEGE',
                'name' => 'College',
                'sort' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        $levelId = Table::uuid();

        $this->db->execute(
            'INSERT INTO levels (id, tenant_id, code, name, sort_order, cycle_id, created_at)
             VALUES (:id, :tenant, :code, :name, :sort, :cycle, :created_at)',
            [
                'id' => $levelId,
                'tenant' => $this->tenantA,
                'code' => '6EME',
                'name' => '6eme',
                'sort' => 1,
                'cycle' => $cycleId,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $levelId;
    }

    private function createClassroom(): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO classrooms (id, tenant_id, code, name, capacity, level_id, section, created_at, updated_at)
             VALUES (:id, :tenant, :code, :name, :capacity, :level, :section, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $this->tenantA,
                'code' => '6EM-A',
                'name' => '6eme A',
                'capacity' => 50,
                'level' => $this->levelId,
                'section' => 'FRANCOPHONE',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    private function createFeeStructure(float $amount): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO fee_structures (id, tenant_id, name, level_id, academic_year_id, total_amount, created_at, updated_at)
             VALUES (:id, :tenant, :name, :level, :year, :amount, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $this->tenantA,
                'name' => 'Scolarite 6eme',
                'level' => $this->levelId,
                'year' => $this->academicYearId,
                'amount' => number_format($amount, 2, '.', ''),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    private function createSubject(string $code, string $name, float $coefficient): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO subjects (id, tenant_id, code, name, coefficient, weekly_hours, category, created_at, updated_at)
             VALUES (:id, :tenant, :code, :name, :coefficient, :hours, :category, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $this->tenantA,
                'code' => $code,
                'name' => $name,
                'coefficient' => $coefficient,
                'hours' => 4,
                'category' => 'SCIENTIFIC',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        // Une affectation est requise : le calcul ne porte que sur les matieres
        // effectivement enseignees dans la classe.
        $this->db->execute(
            'INSERT INTO subject_assignments (id, tenant_id, subject_id, teacher_id, classroom_id, academic_year_id, created_at, updated_at)
             VALUES (:id, :tenant, :subject, :teacher, :classroom, :year, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $this->tenantA,
                'subject' => $id,
                'teacher' => $this->userId,
                'classroom' => $this->classroomId,
                'year' => $this->academicYearId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    private function enrolStudent(string $matricule, string $lastName): string
    {
        $studentId = $this->createStudent($this->tenantA, $matricule, $lastName);

        $this->db->execute(
            'INSERT INTO enrollments (id, tenant_id, student_id, classroom_id, academic_year_id,
                 enrollment_date, type, status, regime, created_at, updated_at)
             VALUES (:id, :tenant, :student, :classroom, :year, :date, :type, :status, :regime, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $this->tenantA,
                'student' => $studentId,
                'classroom' => $this->classroomId,
                'year' => $this->academicYearId,
                'date' => date('Y-m-d H:i:s'),
                'type' => 'NEW',
                'status' => 'ACTIVE',
                'regime' => 'EXTERNAL',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $studentId;
    }

    private function enrolAndInvoice(string $matricule, float $amount): string
    {
        // La grille est mise a jour plutot que recreee : la supprimer echouerait
        // sur la cle etrangere des factures deja emises, exactement comme en
        // production.
        $existing = $this->db->scalar('SELECT id FROM fee_structures LIMIT 1');

        if ($existing === null) {
            $this->createFeeStructure($amount);
        } else {
            $this->db->execute(
                'UPDATE fee_structures SET total_amount = :amount WHERE id = :id',
                ['amount' => number_format($amount, 2, '.', ''), 'id' => $existing]
            );
        }

        $studentId = $this->enrolStudent($matricule, 'FACTURE');
        $enrollmentId = (string) $this->db->scalar(
            'SELECT id FROM enrollments WHERE student_id = :id',
            ['id' => $studentId]
        );

        $billing = new Billing($this->db, $this->app->tenant());

        return (string) $billing->generateInvoice($enrollmentId);
    }

    private function addGrade(
        string $studentId,
        string $subjectId,
        string $periodId,
        ?float $value,
        bool $absent = false,
        bool $justified = false
    ): void {
        $this->db->execute(
            'INSERT INTO grades (id, tenant_id, student_id, subject_id, period_id, teacher_id,
                 type, value, max_value, weight, date, is_absent, is_justified, is_locked, created_at, updated_at)
             VALUES (:id, :tenant, :student, :subject, :period, :teacher,
                 :type, :value, :max_value, :weight, :date, :absent, :justified, :locked, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $this->tenantA,
                'student' => $studentId,
                'subject' => $subjectId,
                'period' => $periodId,
                'teacher' => $this->userId,
                'type' => $absent ? 'HOMEWORK' : 'TEST',
                'value' => $value,
                'max_value' => 20,
                'weight' => 1,
                'date' => date('Y-m-d H:i:s'),
                'absent' => $absent ? 1 : 0,
                'justified' => $justified ? 1 : 0,
                'locked' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
