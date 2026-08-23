<?php

declare(strict_types=1);

namespace Scholaris\Database;

use Scholaris\Auth\Auth;
use Scholaris\Service\Billing;
use Scholaris\Service\GradeCalculator;
use Scholaris\Tenant\TenantContext;

/**
 * Etablissement de demonstration realiste : le Lycee Bilingue de Garoua.
 *
 * Contrairement au seed minimal, celui-ci peuple une ecole entiere et coherente
 * de bout en bout : personnel, matieres, classes des deux sections, eleves
 * rattaches a leurs parents, inscriptions, factures, paiements partiels et
 * notes. Il sert a montrer le produit et a eprouver les ecrans sur des volumes
 * credibles plutot que sur deux lignes de test.
 *
 * Idempotent : le code LBG est cree une seule fois, un second passage n'ajoute
 * rien.
 */
final class DemoSchoolSeeder
{
    private const TENANT_CODE = 'LBG';

    private const TENANT_NAME = 'Lycee Bilingue de Garoua';

    private const PASSWORD = 'Test123!';

    /** Personnel : email, role, prenom, nom, fonction affichee. */
    private const STAFF = [
        ['proviseur@lbg.cm', 'Directeur', 'Amadou', 'BOUBA', 'Proviseur'],
        ['censeur@lbg.cm', 'Censeur', 'Fadimatou', 'ALIOUM', 'Censeur'],
        ['intendant@lbg.cm', 'Intendant', 'Oumarou', 'DJALLO', 'Intendant'],
        ['secretaire@lbg.cm', 'Secrétaire', 'Habiba', 'MOUSSA', 'Secretaire principale'],
        ['admin@lbg.cm', 'Admin Établissement', 'Ibrahim', 'HAMADOU', 'Administrateur'],
        ['infirmiere@lbg.cm', 'Infirmier(ère)', 'Mariama', 'SALI', 'Infirmiere'],
        ['bibliothecaire@lbg.cm', 'Bibliothécaire', 'Yaya', 'NDJIDDA', 'Bibliothecaire'],
        ['chef.sciences@lbg.cm', 'Chef de département', 'Aboubakar', 'GARGA', 'Chef departement Sciences'],
    ];

    /** Enseignants : email, prenom, nom, code matiere principale. */
    private const TEACHERS = [
        ['a.tchoumi@lbg.cm', 'Andre', 'TCHOUMI', 'MATH'],
        ['s.ngono@lbg.cm', 'Solange', 'NGONO', 'FRAN'],
        ['j.wanko@lbg.cm', 'Joseph', 'WANKO', 'ANGL'],
        ['b.oumarou@lbg.cm', 'Bello', 'OUMAROU', 'PHYS'],
        ['c.mbarga@lbg.cm', 'Clarisse', 'MBARGA', 'SVT'],
        ['d.hamadjoda@lbg.cm', 'Daniel', 'HAMADJODA', 'HIST'],
        ['e.njoya@lbg.cm', 'Esther', 'NJOYA', 'PHILO'],
        ['f.sadjo@lbg.cm', 'Fabrice', 'SADJO', 'EPS'],
    ];

    /** Matieres : code, nom, coefficient, heures hebdo, categorie. */
    private const SUBJECTS = [
        ['MATH', 'Mathematiques', 6, 6, 'SCIENTIFIC'],
        ['FRAN', 'Francais', 5, 5, 'LITERARY'],
        ['ANGL', 'Anglais', 4, 4, 'LANGUAGE'],
        ['PHYS', 'Physique-Chimie', 5, 4, 'SCIENTIFIC'],
        ['SVT', 'Sciences de la Vie et de la Terre', 4, 3, 'SCIENTIFIC'],
        ['HIST', 'Histoire-Geographie', 3, 3, 'LITERARY'],
        ['PHILO', 'Philosophie', 3, 2, 'LITERARY'],
        ['EPS', 'Education Physique et Sportive', 2, 2, 'SPORTS'],
    ];

    /** Classes : code, nom, niveau, section, capacite. */
    private const CLASSROOMS = [
        ['6EM-A', '6eme A', '6EME', 'FRANCOPHONE', 60],
        ['6EM-B', '6eme B', '6EME', 'FRANCOPHONE', 60],
        ['F1-A', 'Form 1 A', '6EME', 'ANGLOPHONE', 55],
        ['3EM-A', '3eme A', '3EME', 'FRANCOPHONE', 55],
        ['F4-A', 'Form 4 A', '3EME', 'ANGLOPHONE', 50],
        ['2ND-C', '2nde C', '2NDE', 'FRANCOPHONE', 50],
        ['1ER-C', '1ere C', '1ERE', 'FRANCOPHONE', 45],
        ['TLE-C', 'Terminale C', 'TLE', 'FRANCOPHONE', 40],
        ['TLE-D', 'Terminale D', 'TLE', 'FRANCOPHONE', 45],
        ['US-S', 'Upper Sixth Science', 'TLE', 'ANGLOPHONE', 35],
    ];

    /** Noms de famille du Nord-Cameroun et d'ailleurs, pour des eleves credibles. */
    private const LAST_NAMES = [
        'ABDOULAYE', 'ALHADJI', 'AWALOU', 'BAKARY', 'BELLO', 'DAIROU', 'DJAOURO',
        'FADIL', 'GARGA', 'HAMAN', 'ISSA', 'JIBRILA', 'KAIGAMA', 'LAMINOU',
        'MAHAMAT', 'NASSOUROU', 'OUSMANOU', 'SANDA', 'TIZI', 'YAOUBA',
        'ATANGANA', 'BILOA', 'ESSOMBA', 'FOUDA', 'MBALLA', 'NGUEMA', 'ONANA',
        'TCHINDA', 'WADJIRI', 'ZANGA',
    ];

    private const MALE_NAMES = [
        'Aboubakar', 'Adamou', 'Alioum', 'Bachirou', 'Boubakari', 'Djibrilla',
        'Hamidou', 'Idrissou', 'Mohamadou', 'Nourou', 'Oumarou', 'Saidou',
        'Aristide', 'Blaise', 'Cedric', 'Emmanuel', 'Herve', 'Landry',
    ];

    private const FEMALE_NAMES = [
        'Aichatou', 'Amina', 'Asmaou', 'Djamila', 'Fadimatou', 'Halimatou',
        'Hapsatou', 'Maimouna', 'Ramatou', 'Zenabou', 'Bernadette', 'Chantal',
        'Estelle', 'Josiane', 'Larissa', 'Nadege', 'Sandrine', 'Viviane',
    ];

    private Connection $db;

    private TenantContext $tenant;

    private string $basePath;

    /** @var array<string, string> code de matiere vers identifiant */
    private array $subjectIds = [];

    /** @var array<string, string> code de classe vers identifiant */
    private array $classroomIds = [];

    /** @var array<string, string> email vers identifiant utilisateur */
    private array $userIds = [];

    public function __construct(Connection $db, TenantContext $tenant, string $basePath)
    {
        $this->db = $db;
        $this->tenant = $tenant;
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * @return list<string> compte rendu
     */
    public function run(): array
    {
        // Graine fixe : deux executions produisent le meme etablissement, ce
        // qui rend les captures d'ecran et les tests manuels reproductibles.
        mt_srand(20260823);

        $existing = $this->db->scalar('SELECT id FROM tenants WHERE code = :code', ['code' => self::TENANT_CODE]);

        if ($existing !== null) {
            $this->tenant->set((string) $existing);

            return ['Le Lycee Bilingue de Garoua existe deja (code '.self::TENANT_CODE.'), rien a faire.'];
        }

        $report = [];
        $tenantId = $this->createTenant();
        $this->tenant->set($tenantId);

        $academicYearId = $this->createAcademicYear($tenantId);
        $periodIds = $this->createPeriods($academicYearId);
        $report[] = 'annee academique et 6 sequences';

        $this->createStructure($tenantId);
        $report[] = '3 cycles, 13 niveaux';

        $staffCount = $this->createStaff($tenantId);
        $report[] = $staffCount.' membres du personnel';

        $this->createSubjects($tenantId);
        $report[] = count(self::SUBJECTS).' matieres';

        $this->createClassrooms($tenantId);
        $report[] = count(self::CLASSROOMS).' classes (francophones et anglophones)';

        $assignments = $this->createAssignments($academicYearId);
        $report[] = $assignments.' affectations enseignant-matiere-classe';

        $feeStructures = $this->createFeeStructures($academicYearId);
        $report[] = $feeStructures.' grilles tarifaires';

        $counts = $this->createStudentsAndFamilies($tenantId, $academicYearId);
        $report[] = $counts['students'].' eleves, '.$counts['parents'].' parents';
        $report[] = $counts['invoices'].' factures, '.$counts['payments'].' paiements';

        $grades = $this->createGrades($periodIds[0]);
        $report[] = $grades.' notes saisies sur la sequence 1';

        $this->calculateResults($periodIds[0]);
        $report[] = 'moyennes et classements calcules';

        $family = $this->createFamilyAccounts($tenantId);
        $report[] = 'comptes famille : '.$family;

        return $report;
    }

    /**
     * Comptes de connexion pour une famille reelle de l'etablissement.
     *
     * Le compte eleve est rattache a son dossier, le compte parent au parent de
     * ce meme eleve : c'est cette liaison qui permet de verifier qu'un parent
     * ne voit que ses enfants, et un eleve que lui-meme.
     */
    private function createFamilyAccounts(string $tenantId): string
    {
        $student = $this->db->selectOne(
            'SELECT s.id, s.matricule, s.first_name, s.last_name
             FROM students s WHERE s.tenant_id = :tenant ORDER BY s.matricule LIMIT 1',
            ['tenant' => $tenantId]
        );

        if ($student === null) {
            return 'aucun eleve disponible';
        }

        $studentUserId = $this->createUser(
            $tenantId,
            'eleve@lbg.cm',
            (string) $student['first_name'],
            (string) $student['last_name']
        );
        $this->assignRole($studentUserId, 'Élève');

        $this->db->execute(
            'UPDATE students SET user_id = :user WHERE id = :id',
            ['user' => $studentUserId, 'id' => $student['id']]
        );

        $parent = $this->db->selectOne(
            'SELECT p.id, p.first_name, p.last_name FROM parents p
             INNER JOIN student_parents sp ON sp.parent_id = p.id
             WHERE sp.student_id = :student LIMIT 1',
            ['student' => $student['id']]
        );

        if ($parent !== null) {
            $parentUserId = $this->createUser(
                $tenantId,
                'parent@lbg.cm',
                (string) $parent['first_name'],
                (string) $parent['last_name']
            );
            $this->assignRole($parentUserId, 'Parent');

            $this->db->execute(
                'UPDATE parents SET user_id = :user WHERE id = :id',
                ['user' => $parentUserId, 'id' => $parent['id']]
            );
        }

        return 'eleve@lbg.cm et parent@lbg.cm, rattaches a '
            .$student['last_name'].' '.$student['first_name']
            .' ('.$student['matricule'].')';
    }

    private function createTenant(): string
    {
        $id = Table::uuid();
        $now = $this->now();

        $this->db->execute(
            'INSERT INTO tenants (id, code, name, type, status, address, phone, email,
                 public_enrollment_enabled, created_at, updated_at)
             VALUES (:id, :code, :name, :type, :status, :address, :phone, :email,
                 :public_enrollment, :created_at, :updated_at)',
            [
                'id' => $id,
                'code' => self::TENANT_CODE,
                'name' => self::TENANT_NAME,
                'type' => 'SECONDAIRE',
                'status' => 'PUBLIC',
                'address' => 'Quartier Poumpoumre, Garoua, Region du Nord',
                'phone' => '+237 222 27 12 34',
                'email' => 'contact@lyceebilingue-garoua.cm',
                // Ouvre la pre-inscription en ligne : cet etablissement sert
                // aussi a demontrer le parcours public.
                'public_enrollment' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $id;
    }

    private function createAcademicYear(string $tenantId): string
    {
        $start = new \DateTimeImmutable(date('Y').'-09-02');
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO academic_years (id, tenant_id, label, start_date, end_date, status, created_at)
             VALUES (:id, :tenant, :label, :start, :end, :status, :created_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'label' => $start->format('Y').'-'.$start->modify('+1 year')->format('Y'),
                'start' => $start->format('Y-m-d'),
                'end' => $start->modify('+10 months')->format('Y-m-d'),
                'status' => 'ACTIVE',
                'created_at' => $this->now(),
            ]
        );

        return $id;
    }

    /**
     * @return list<string>
     */
    private function createPeriods(string $academicYearId): array
    {
        $start = new \DateTimeImmutable(date('Y').'-09-02');
        $ids = [];

        for ($number = 1; $number <= 6; $number++) {
            $id = Table::uuid();
            $ids[] = $id;

            $this->db->execute(
                'INSERT INTO periods (id, academic_year_id, type, number, start_date, end_date, grading_status)
                 VALUES (:id, :year, :type, :number, :start, :end, :status)',
                [
                    'id' => $id,
                    'year' => $academicYearId,
                    'type' => 'SEQUENCE',
                    'number' => $number,
                    'start' => $start->modify('+'.(($number - 1) * 6).' weeks')->format('Y-m-d'),
                    'end' => $start->modify('+'.($number * 6).' weeks')->format('Y-m-d'),
                    // Seule la sequence 1 est ouverte : les enseignants y
                    // saisissent, les suivantes attendent leur tour.
                    'status' => $number === 1 ? 'OPEN' : 'CLOSED',
                ]
            );
        }

        return $ids;
    }

    private function createStructure(string $tenantId): void
    {
        $cycles = [
            ['PRIMAIRE', 'Primaire', [['SIL', 'SIL'], ['CP', 'CP'], ['CE1', 'CE1'], ['CE2', 'CE2'], ['CM1', 'CM1'], ['CM2', 'CM2']]],
            ['COLLEGE', 'Secondaire 1er cycle', [['6EME', '6eme / Form 1'], ['5EME', '5eme / Form 2'], ['4EME', '4eme / Form 3'], ['3EME', '3eme / Form 4']]],
            ['LYCEE', 'Secondaire 2nd cycle', [['2NDE', '2nde / Lower Sixth'], ['1ERE', '1ere'], ['TLE', 'Terminale / Upper Sixth']]],
        ];

        foreach ($cycles as $cycleOrder => [$code, $name, $levels]) {
            $cycleId = Table::uuid();

            $this->db->execute(
                'INSERT INTO cycles (id, tenant_id, code, name, sort_order, created_at)
                 VALUES (:id, :tenant, :code, :name, :sort, :created_at)',
                [
                    'id' => $cycleId,
                    'tenant' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'sort' => $cycleOrder + 1,
                    'created_at' => $this->now(),
                ]
            );

            foreach ($levels as $levelOrder => [$levelCode, $levelName]) {
                $this->db->execute(
                    'INSERT INTO levels (id, tenant_id, code, name, sort_order, cycle_id, created_at)
                     VALUES (:id, :tenant, :code, :name, :sort, :cycle, :created_at)',
                    [
                        'id' => Table::uuid(),
                        'tenant' => $tenantId,
                        'code' => $levelCode,
                        'name' => $levelName,
                        'sort' => $levelOrder + 1,
                        'cycle' => $cycleId,
                        'created_at' => $this->now(),
                    ]
                );
            }
        }
    }

    private function createStaff(string $tenantId): int
    {
        foreach (self::STAFF as [$email, $role, $firstName, $lastName, $position]) {
            $userId = $this->createUser($tenantId, $email, $firstName, $lastName);
            $this->assignRole($userId, $role);
            $this->createEmployee($tenantId, $userId, $firstName, $lastName, $position);
        }

        foreach (self::TEACHERS as [$email, $firstName, $lastName, $subjectCode]) {
            $userId = $this->createUser($tenantId, $email, $firstName, $lastName);
            $this->assignRole($userId, 'Enseignant');
            $this->createEmployee($tenantId, $userId, $firstName, $lastName, 'Enseignant');
        }

        return count(self::STAFF) + count(self::TEACHERS);
    }

    private function createSubjects(string $tenantId): void
    {
        foreach (self::SUBJECTS as [$code, $name, $coefficient, $hours, $category]) {
            $id = Table::uuid();
            $this->subjectIds[$code] = $id;

            $this->db->execute(
                'INSERT INTO subjects (id, tenant_id, code, name, coefficient, weekly_hours, category,
                     is_eliminatory, eliminatory_threshold, created_at, updated_at)
                 VALUES (:id, :tenant, :code, :name, :coefficient, :hours, :category,
                     :eliminatory, :threshold, :created_at, :updated_at)',
                [
                    'id' => $id,
                    'tenant' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'coefficient' => $coefficient,
                    'hours' => $hours,
                    'category' => $category,
                    'eliminatory' => 0,
                    'threshold' => 0,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    private function createClassrooms(string $tenantId): void
    {
        foreach (self::CLASSROOMS as $index => [$code, $name, $levelCode, $section, $capacity]) {
            $levelId = $this->db->scalar(
                'SELECT id FROM levels WHERE tenant_id = :tenant AND code = :code',
                ['tenant' => $tenantId, 'code' => $levelCode]
            );

            if ($levelId === null) {
                continue;
            }

            // Professeur principal pris a tour de role parmi les enseignants.
            $teacherEmail = self::TEACHERS[$index % count(self::TEACHERS)][0];
            $id = Table::uuid();
            $this->classroomIds[$code] = $id;

            $this->db->execute(
                'INSERT INTO classrooms (id, tenant_id, code, name, capacity, level_id,
                     main_teacher_id, section, created_at, updated_at)
                 VALUES (:id, :tenant, :code, :name, :capacity, :level,
                     :teacher, :section, :created_at, :updated_at)',
                [
                    'id' => $id,
                    'tenant' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'capacity' => $capacity,
                    'level' => $levelId,
                    'teacher' => $this->userIds[$teacherEmail] ?? null,
                    'section' => $section,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    private function createAssignments(string $academicYearId): int
    {
        $count = 0;

        foreach ($this->classroomIds as $classroomId) {
            foreach (self::TEACHERS as [$email, , , $subjectCode]) {
                if (! isset($this->subjectIds[$subjectCode], $this->userIds[$email])) {
                    continue;
                }

                $this->db->execute(
                    'INSERT INTO subject_assignments (id, tenant_id, subject_id, teacher_id,
                         classroom_id, academic_year_id, created_at, updated_at)
                     VALUES (:id, :tenant, :subject, :teacher, :classroom, :year, :created_at, :updated_at)',
                    [
                        'id' => Table::uuid(),
                        'tenant' => $this->tenant->requireId(),
                        'subject' => $this->subjectIds[$subjectCode],
                        'teacher' => $this->userIds[$email],
                        'classroom' => $classroomId,
                        'year' => $academicYearId,
                        'created_at' => $this->now(),
                        'updated_at' => $this->now(),
                    ]
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * Tarifs differencies par cycle, ordres de grandeur d'un lycee public
     * camerounais.
     */
    private function createFeeStructures(string $academicYearId): int
    {
        $tarifs = [
            ['6EME', 'Scolarite 6eme / Form 1', 75000],
            ['3EME', 'Scolarite 3eme / Form 4', 85000],
            ['2NDE', 'Scolarite 2nde', 95000],
            ['1ERE', 'Scolarite 1ere', 100000],
            ['TLE', 'Scolarite Terminale', 110000],
        ];

        $count = 0;

        foreach ($tarifs as [$levelCode, $name, $amount]) {
            $levelId = $this->db->scalar(
                'SELECT id FROM levels WHERE tenant_id = :tenant AND code = :code',
                ['tenant' => $this->tenant->requireId(), 'code' => $levelCode]
            );

            if ($levelId === null) {
                continue;
            }

            $structureId = Table::uuid();

            $this->db->execute(
                'INSERT INTO fee_structures (id, tenant_id, name, level_id, academic_year_id,
                     total_amount, created_at, updated_at)
                 VALUES (:id, :tenant, :name, :level, :year, :amount, :created_at, :updated_at)',
                [
                    'id' => $structureId,
                    'tenant' => $this->tenant->requireId(),
                    'name' => $name,
                    'level' => $levelId,
                    'year' => $academicYearId,
                    'amount' => number_format($amount, 2, '.', ''),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );

            // Trois tranches : rentree, janvier, avril.
            $tranches = [
                ['1ere tranche (rentree)', 0.4, date('Y').'-10-15'],
                ['2eme tranche (janvier)', 0.3, (date('Y') + 1).'-01-15'],
                ['3eme tranche (avril)', 0.3, (date('Y') + 1).'-04-15'],
            ];

            foreach ($tranches as $order => [$label, $ratio, $dueDate]) {
                $this->db->execute(
                    'INSERT INTO fee_installments (id, tenant_id, fee_structure_id, label, amount,
                         due_date, sort_order, created_at, updated_at)
                     VALUES (:id, :tenant, :structure, :label, :amount, :due_date, :sort, :created_at, :updated_at)',
                    [
                        'id' => Table::uuid(),
                        'tenant' => $this->tenant->requireId(),
                        'structure' => $structureId,
                        'label' => $label,
                        'amount' => number_format($amount * $ratio, 2, '.', ''),
                        'due_date' => $dueDate,
                        'sort' => $order + 1,
                        'created_at' => $this->now(),
                        'updated_at' => $this->now(),
                    ]
                );
            }

            $count++;
        }

        return $count;
    }

    /**
     * Eleves, parents, inscriptions, factures et paiements partiels.
     *
     * @return array{students: int, parents: int, invoices: int, payments: int}
     */
    private function createStudentsAndFamilies(string $tenantId, string $academicYearId): array
    {
        $billing = new Billing($this->db, $this->tenant);
        $counts = ['students' => 0, 'parents' => 0, 'invoices' => 0, 'payments' => 0];
        $sequence = 0;
        $intendantId = $this->userIds['intendant@lbg.cm'] ?? null;

        foreach ($this->classroomIds as $code => $classroomId) {
            // Effectifs volontairement inegaux : une ecole reelle n'a pas des
            // classes toutes identiques.
            $size = mt_rand(18, 28);

            for ($i = 0; $i < $size; $i++) {
                $sequence++;
                $isMale = mt_rand(0, 1) === 1;
                $firstName = $isMale
                    ? self::MALE_NAMES[array_rand(self::MALE_NAMES)]
                    : self::FEMALE_NAMES[array_rand(self::FEMALE_NAMES)];
                $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

                $studentId = $this->createStudent(
                    $tenantId,
                    sprintf('%s/%s/%04d', self::TENANT_CODE, date('Y'), $sequence),
                    $firstName,
                    $lastName,
                    $isMale ? 'MALE' : 'FEMALE'
                );

                $counts['students']++;
                $counts['parents'] += $this->createParent($tenantId, $studentId, $lastName, $isMale);

                $enrollmentId = $this->createEnrollment($studentId, $classroomId, $academicYearId);
                $invoiceId = $billing->generateInvoice($enrollmentId);

                if ($invoiceId === null) {
                    continue;
                }

                $counts['invoices']++;

                // Trois familles sur quatre ont commence a payer : le tableau
                // de bord financier montre alors un vrai taux de recouvrement.
                if (mt_rand(1, 4) === 1) {
                    continue;
                }

                $invoice = $this->db->selectOne(
                    'SELECT total_amount FROM invoices WHERE id = :id',
                    ['id' => $invoiceId]
                );

                $total = (float) ($invoice['total_amount'] ?? 0);
                $ratio = [0.4, 0.7, 1.0][mt_rand(0, 2)];
                $amount = round($total * $ratio / 500) * 500;

                if ($amount <= 0) {
                    continue;
                }

                $billing->recordPayment(
                    $invoiceId,
                    $amount,
                    ['CASH', 'MOBILE_MONEY', 'BANK_TRANSFER'][mt_rand(0, 2)],
                    null,
                    $intendantId,
                    null
                );

                $counts['payments']++;
            }
        }

        return $counts;
    }

    private function createStudent(
        string $tenantId,
        string $matricule,
        string $firstName,
        string $lastName,
        string $gender
    ): string {
        $id = Table::uuid();
        $birthYear = (int) date('Y') - mt_rand(12, 19);

        $this->db->execute(
            'INSERT INTO students (id, tenant_id, matricule, first_name, last_name, date_of_birth,
                 place_of_birth, gender, nationality, status, created_at, updated_at)
             VALUES (:id, :tenant, :matricule, :first, :last, :dob,
                 :place, :gender, :nationality, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'matricule' => $matricule,
                'first' => $firstName,
                'last' => $lastName,
                'dob' => sprintf('%d-%02d-%02d', $birthYear, mt_rand(1, 12), mt_rand(1, 28)),
                'place' => ['Garoua', 'Maroua', 'Ngaoundere', 'Guider', 'Figuil'][mt_rand(0, 4)],
                'gender' => $gender,
                'nationality' => 'Camerounaise',
                'status' => 'ACTIVE',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $id;
    }

    private function createParent(string $tenantId, string $studentId, string $lastName, bool $childIsMale): int
    {
        $isFather = mt_rand(0, 1) === 1;
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO parents (id, tenant_id, first_name, last_name, phone, whatsapp,
                 profession, address, relationship, created_at, updated_at)
             VALUES (:id, :tenant, :first, :last, :phone, :whatsapp,
                 :profession, :address, :relationship, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'first' => $isFather
                    ? self::MALE_NAMES[array_rand(self::MALE_NAMES)]
                    : self::FEMALE_NAMES[array_rand(self::FEMALE_NAMES)],
                'last' => $lastName,
                'phone' => '+2376'.mt_rand(70000000, 99999999),
                'whatsapp' => '+2376'.mt_rand(70000000, 99999999),
                'profession' => ['Commercant', 'Enseignant', 'Agriculteur', 'Fonctionnaire', 'Infirmier', 'Chauffeur'][mt_rand(0, 5)],
                'address' => 'Garoua, quartier '.['Poumpoumre', 'Roumde Adjia', 'Djamboutou', 'Plateau'][mt_rand(0, 3)],
                'relationship' => $isFather ? 'FATHER' : 'MOTHER',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        $this->db->execute(
            'INSERT INTO student_parents (student_id, parent_id, relationship)
             VALUES (:student, :parent, :relationship)',
            [
                'student' => $studentId,
                'parent' => $id,
                'relationship' => $isFather ? 'FATHER' : 'MOTHER',
            ]
        );

        return 1;
    }

    private function createEnrollment(string $studentId, string $classroomId, string $academicYearId): string
    {
        $id = Table::uuid();

        $this->db->execute(
            'INSERT INTO enrollments (id, tenant_id, student_id, classroom_id, academic_year_id,
                 enrollment_date, type, status, regime, is_repeater, created_at, updated_at)
             VALUES (:id, :tenant, :student, :classroom, :year,
                 :enrollment_date, :type, :status, :regime, :repeater, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $this->tenant->requireId(),
                'student' => $studentId,
                'classroom' => $classroomId,
                'year' => $academicYearId,
                'enrollment_date' => date('Y').'-09-05 08:00:00',
                'type' => mt_rand(1, 5) === 1 ? 'TRANSFER' : 'NEW',
                'status' => 'ACTIVE',
                'regime' => ['EXTERNAL', 'EXTERNAL', 'EXTERNAL', 'HALF_BOARD', 'BOARDING'][mt_rand(0, 4)],
                'repeater' => mt_rand(1, 8) === 1 ? 1 : 0,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $id;
    }

    /**
     * Deux evaluations par matiere sur la sequence 1.
     */
    private function createGrades(string $periodId): int
    {
        $count = 0;

        foreach ($this->classroomIds as $classroomId) {
            $students = $this->db->select(
                'SELECT student_id FROM enrollments
                 WHERE tenant_id = :tenant AND classroom_id = :classroom AND status = :status',
                [
                    'tenant' => $this->tenant->requireId(),
                    'classroom' => $classroomId,
                    'status' => 'ACTIVE',
                ]
            );

            foreach (self::TEACHERS as [$email, , , $subjectCode]) {
                if (! isset($this->subjectIds[$subjectCode], $this->userIds[$email])) {
                    continue;
                }

                foreach ($students as $row) {
                    foreach (['TEST', 'HOMEWORK'] as $type) {
                        // Distribution centree autour de 11 sur 20, avec de
                        // rares absences : proche d'une classe reelle.
                        $isAbsent = mt_rand(1, 40) === 1;
                        $value = $isAbsent ? null : min(20, max(0, round(mt_rand(40, 180) / 10, 2)));

                        $this->db->execute(
                            'INSERT INTO grades (id, tenant_id, student_id, subject_id, period_id, teacher_id,
                                 type, value, max_value, weight, date, is_absent, is_justified, is_locked,
                                 created_at, updated_at)
                             VALUES (:id, :tenant, :student, :subject, :period, :teacher,
                                 :type, :value, :max_value, :weight, :date, :absent, :justified, :locked,
                                 :created_at, :updated_at)',
                            [
                                'id' => Table::uuid(),
                                'tenant' => $this->tenant->requireId(),
                                'student' => $row['student_id'],
                                'subject' => $this->subjectIds[$subjectCode],
                                'period' => $periodId,
                                'teacher' => $this->userIds[$email],
                                'type' => $type,
                                'value' => $value,
                                'max_value' => 20,
                                'weight' => $type === 'TEST' ? 2 : 1,
                                'date' => $this->now(),
                                'absent' => $isAbsent ? 1 : 0,
                                'justified' => 0,
                                'locked' => 0,
                                'created_at' => $this->now(),
                                'updated_at' => $this->now(),
                            ]
                        );

                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function calculateResults(string $periodId): void
    {
        $calculator = new GradeCalculator($this->db, $this->tenant);

        foreach ($this->classroomIds as $classroomId) {
            $calculator->calculate($classroomId, $periodId);
        }
    }

    private function createUser(string $tenantId, string $email, string $firstName, string $lastName): string
    {
        $id = Table::uuid();
        $this->userIds[$email] = $id;

        $this->db->execute(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, phone,
                 status, created_at, updated_at)
             VALUES (:id, :tenant, :email, :hash, :first, :last, :phone, :status, :created_at, :updated_at)',
            [
                'id' => $id,
                'tenant' => $tenantId,
                'email' => $email,
                'hash' => Auth::hash(self::PASSWORD),
                'first' => $firstName,
                'last' => $lastName,
                'phone' => '+2376'.mt_rand(70000000, 99999999),
                'status' => 'ACTIVE',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $id;
    }

    private function createEmployee(
        string $tenantId,
        string $userId,
        string $firstName,
        string $lastName,
        string $position
    ): void {
        $this->db->execute(
            'INSERT INTO employees (id, tenant_id, user_id, first_name, last_name, position,
                 hire_date, status, created_at, updated_at)
             VALUES (:id, :tenant, :user, :first, :last, :position, :hire_date, :status, :created_at, :updated_at)',
            [
                'id' => Table::uuid(),
                'tenant' => $tenantId,
                'user' => $userId,
                'first' => $firstName,
                'last' => $lastName,
                'position' => $position,
                'hire_date' => (date('Y') - mt_rand(1, 15)).'-09-01',
                'status' => 'ACTIVE',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );
    }

    private function assignRole(string $userId, string $roleName): void
    {
        $roleId = $this->db->scalar(
            'SELECT id FROM roles WHERE tenant_id IS NULL AND name = :name',
            ['name' => $roleName]
        );

        if ($roleId !== null) {
            $this->db->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                ['user' => $userId, 'role' => $roleId]
            );
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
