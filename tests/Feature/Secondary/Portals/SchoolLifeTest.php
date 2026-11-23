<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Portals;

use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageLine;
use Scholaris\Modules\Apps\Secondary\Syllabus\Contracts\CoverageReader;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\AssignmentInfo;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\EntryInfo;
use Scholaris\Modules\Apps\Secondary\Timetable\Contracts\TeachingAssignments;
use Scholaris\Modules\Socle\Assessment\Contracts\PublishedResult;
use Scholaris\Modules\Socle\Assessment\Contracts\ResultsDirectory;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummaryProvider;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureDraft;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureWriter;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Portals\Contracts\AdultLearnerCheck;
use Tests\Support\Billing\InMemoryEnrollments;
use Tests\Support\Billing\InMemoryPeople;
use Tests\Support\Secondary\Portals\PortalWorld;
use Tests\Support\Secondary\SecondaryTestCase;

/**
 * T974 : vie scolaire des portails du secondaire. Élève et parent : résultats publiés de l'établissement seulement, bulletins émis
 * (retenus si blocage), absences, devoirs récents ; un parent ne lit que ses enfants (404) ; accès restreint d'un majeur sans
 * consentement. Enseignant : ses affectations avec la couverture du programme et les devoirs donnés. Données fictives.
 */
final class SchoolLifeTest extends SecondaryTestCase
{
    private const PARENT = 'compte-parent-1';
    private const OTHER_PARENT = 'compte-parent-2';
    private const STUDENT = 'compte-eleve-1';
    private const TEACHER = 'compte-prof-1';

    private InMemoryEnrollments $enrollments;
    private InMemoryPeople $people;
    private PortalWorld $world;
    private string $school;
    private string $class;

    protected function setUp(): void
    {
        $this->enrollments = new InMemoryEnrollments();
        $this->people = new InMemoryPeople();
        $this->world = new PortalWorld();
        $test = $this;
        $results = new class ($test) implements ResultsDirectory {
            public function __construct(private readonly SchoolLifeTest $test)
            {
            }

            public function publishedFor(string $tenantId, string $personId): array
            {
                return $personId !== 'eleve-1' ? [] : [
                    new PublishedResult('r1', 'eleve-1', $this->test->schoolId(), 'p-t1', 'Trimestre 1', 12.4, 3, 40, null),
                    new PublishedResult('r0', 'eleve-1', 'autre-etablissement', 'p-x', 'Ancien établissement', 9.0, 20, 30, 'Redouble'),
                ];
            }
        };
        $attendance = new class () implements AttendanceSummaryProvider {
            public function forLearner(string $tenantId, string $establishmentId, string $learnerId, string $from, string $to): AttendanceSummary
            {
                return new AttendanceSummary(120, 6, 2, 3);
            }

            public function absentCountOn(string $tenantId, string $establishmentId, string $date): int
            {
                return 0;
            }
        };
        $assignments = new class ($test) implements TeachingAssignments {
            public function __construct(private readonly SchoolLifeTest $test)
            {
            }

            public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo
            {
                return null;
            }

            public function forTeacher(string $tenantId, string $establishmentId, string $personId, ?string $academicYearId = null): array
            {
                return $personId !== 'prof-1' ? [] : [new AssignmentInfo('aff-1', 'ay-2026', $this->test->classId(), null, 'm-1', 's-1', 'MATH', 'Mathématiques', 'prof-1', 'permanent', 'standard', null, 'active')];
            }

            public function forClass(string $tenantId, string $establishmentId, string $classId, ?string $academicYearId = null): array
            {
                return [];
            }

            public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array
            {
                return [];
            }

            public function entry(string $tenantId, string $establishmentId, string $entryId): ?EntryInfo
            {
                return null;
            }

            public function entriesOf(string $tenantId, string $establishmentId, string $assignmentId): array
            {
                return [];
            }
        };
        $coverage = new class () implements CoverageReader {
            public function coverage(string $tenantId, string $establishmentId, string $academicYearId, string $classId): array
            {
                return [new CoverageLine('aff-1', $classId, null, 'm-1', 'Mathématiques', 'prof-1', 40.0, 10.0, 0.25, 20, 5, '2026-10-20')];
            }
        };
        $documents = new class () implements DocumentCatalog {
            public function validFor(string $tenantId, string $personId, int $limit = 50): array
            {
                return $personId !== 'eleve-1' ? [] : [
                    new IssuedDocument('doc-b1', 'BUL-26-T-000001', 'jeton-b1', IssuedDocument::VALID, 'secondary_report_trimester', 'eleve-1'),
                    new IssuedDocument('doc-c1', 'CS-26-C-000001', 'jeton-c1', IssuedDocument::VALID, 'sec_doc03_school_certificate', 'eleve-1'),
                ];
            }
        };
        $this->bootSecondary([
            EnrollmentDirectory::class => $this->enrollments, PersonDirectory::class => $this->people, InvoiceIssuer::class => $this->world,
            PaymentRequester::class => $this->world, DebtorDirectory::class => $this->world, UserPersonLink::class => $this->world,
            AdultLearnerCheck::class => $this->world, ConsentChecker::class => $this->world, 'secondary.student_facts.test' => [$this->world],
            ResultsDirectory::class => $results, AttendanceSummaryProvider::class => $attendance, TeachingAssignments::class => $assignments,
            CoverageReader::class => $coverage, DocumentCatalog::class => $documents,
        ]);
        $this->school = $this->factory->opened(self::TA, 'college_prive');
        $writer = $this->service(StructureWriter::class);
        $level = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::LEVEL, '5e'));
        $this->class = $writer->createStructure(self::TA, $this->school, new StructureDraft(StructureDraft::SCHOOL_CLASS, '5e A', null, $level));
        foreach ([['eleve-1', 'Aïcha'], ['eleve-3', 'Carine'], ['parent-1', 'Mariam'], ['parent-2', 'Joseph'], ['prof-1', 'Paul']] as [$id, $first]) {
            $this->people->add(self::TA, $id, $first, 'Exemple');
        }
        $this->people->link(self::TA, 'parent-1', 'eleve-1');
        $this->people->link(self::TA, 'parent-2', 'eleve-3');
        $this->enrollments->add(self::TA, 'ins-1', 'eleve-1', $this->school, $this->class, 'ay-2026');
        $this->enrollments->add(self::TA, 'ins-3', 'eleve-3', $this->school, $this->class, 'ay-2026');
        $this->world->link(self::TA, self::PARENT, 'parent-1');
        $this->world->link(self::TA, self::OTHER_PARENT, 'parent-2');
        $this->world->link(self::TA, self::STUDENT, 'eleve-1');
        $this->world->link(self::TA, self::TEACHER, 'prof-1');
        $this->grant(self::TA, self::PARENT, $this->school, ['secondary.family.read']);
        $this->grant(self::TA, self::OTHER_PARENT, $this->school, ['secondary.family.read']);
        $this->grant(self::TA, self::STUDENT, $this->school, ['secondary.self.read']);
        $this->grant(self::TA, self::TEACHER, $this->school, ['secondary.lesson.log']);
        $insert = $this->pdo()->prepare("INSERT INTO se_lesson_logs (id, tenant_id, establishment_id, academic_year_id, structure_id, assignment_id, se_subject_id, subject_id, subject_label, teacher_person_id, session_date, content, homework, hours, signed_by, signed_at, signature_hash, client_uuid, created_at) VALUES (?, ?, ?, 'ay-2026', ?, 'aff-1', 'm-1', 's-1', 'Mathématiques', 'prof-1', ?, 'Séance fictive', ?, 1, 'prof-1', '2026-10-20T10:00:00+01:00', ?, ?, '2026-10-20T10:00:00+01:00')");
        foreach ([['l1', '2026-10-20', 'Exercices 1 à 5 page 12'], ['l2', '2026-10-21', null], ['l3', '2026-09-01', 'Ancien devoir']] as [$id, $date, $homework]) {
            $insert->execute([$id, self::TA, $this->school, $this->class, $date, $homework, str_repeat('a', 64), 'uuid-' . $id]);
        }
    }

    public function schoolId(): string
    {
        return $this->school;
    }

    public function classId(): string
    {
        return $this->class;
    }

    public function testEleveEtParentVoientLaVieScolaire(): void
    {
        $own = $this->ok('GET', '/secondary/portal/student/school-life', null, self::STUDENT, self::TA, $this->school);
        self::assertSame(['Trimestre 1'], array_column($own['results'], 'period_label'), 'Résultats publiés de cet établissement seulement.');
        self::assertSame([12.4, 3, 40], [$own['results'][0]['average'], $own['results'][0]['rank'], $own['results'][0]['class_size']]);
        self::assertSame([6, 2, 3], [$own['attendance']['absences'], $own['attendance']['unjustified_absences'], $own['attendance']['lates']]);
        self::assertSame([['session_date' => '2026-10-20', 'subject_label' => 'Mathématiques', 'homework' => 'Exercices 1 à 5 page 12']], $own['homework']);
        self::assertSame([], $own['services']);
        self::assertSame(['BUL-26-T-000001'], array_column($own['report_cards']['items'], 'number'), 'Bulletins du socle seulement, sans les autres documents.');
        self::assertFalse($own['report_cards']['blocked']);

        $child = $this->ok('GET', '/secondary/portal/family/children/eleve-1/school-life', null, self::PARENT, self::TA, $this->school);
        self::assertSame('full', $child['access']);
        self::assertSame(404, $this->call('GET', '/secondary/portal/family/children/eleve-3/school-life', null, self::PARENT, self::TA, $this->school)[0], 'Enfant d\'une autre famille.');
        self::assertSame(403, $this->call('GET', '/secondary/portal/student/school-life', null, self::PARENT, self::TA, $this->school)[0]);

        $this->world->blocked['eleve-1'] = true;
        $blocked = $this->ok('GET', '/secondary/portal/family/children/eleve-1/school-life', null, self::PARENT, self::TA, $this->school);
        self::assertTrue($blocked['report_cards']['blocked']);
        self::assertSame([], $blocked['report_cards']['items']);
        self::assertNotEmpty($blocked['results'], 'Les résultats restent visibles (RG-FIN-1).');

        $this->world->adults['eleve-1'] = true;
        $restricted = $this->ok('GET', '/secondary/portal/family/children/eleve-1/school-life', null, self::PARENT, self::TA, $this->school);
        self::assertSame(['person_id' => 'eleve-1', 'access' => 'restricted'], $restricted);
    }

    public function testPortailEnseignant(): void
    {
        $portal = $this->ok('GET', '/secondary/portal/teacher', null, self::TEACHER, self::TA, $this->school);
        self::assertSame('prof-1', $portal['teacher_person_id']);
        self::assertSame(['Mathématiques', '5e A', 0.25], [$portal['assignments'][0]['subject_label'], $portal['assignments'][0]['class_name'], $portal['assignments'][0]['coverage']['rate']]);
        self::assertSame(['Exercices 1 à 5 page 12'], array_column($portal['homework_given'], 'homework'));
        self::assertSame(403, $this->call('GET', '/secondary/portal/teacher', null, self::PARENT, self::TA, $this->school)[0]);
    }
}
