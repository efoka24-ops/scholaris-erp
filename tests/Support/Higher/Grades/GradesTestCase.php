<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Grades;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\FinancialHolds;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Grades\Application\GradesClock;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\PublicationGuard;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Tests\Support\Higher\Academic\CurriculumTestCase;

/**
 * Base des tests de Grades : organisation et maquette RÉELLES (Academic, Curriculum : Marie N., L1 S1 publiée), doublures des
 * inscriptions, comptes, personnel et affectations, blocages financiers, notifications, 2FA et garde de publication.
 *
 * Acteurs (client A) : enseignant de Mathématiques financières (tous groupes), enseignant de Comptabilité générale, chef de
 * département (R05), directeur d'école (R04), scolarité (R07), service des examens (R08), étudiante Marie (p1, groupe G1).
 */
abstract class GradesTestCase extends CurriculumTestCase
{
    public const TEACHER = 'teacher-mf';
    public const OTHER_TEACHER = 'teacher-cg';
    public const CHEF = 'chef-dept';
    public const DIRECTOR = 'dir-ecole';
    public const REGISTRAR = 'scolarite';
    public const EXAMS = 'examens';
    public const STUDENT = 'student-marie';

    protected GradesFakes $fakes;
    protected GradesClock $clock;

    /** @var array{id: string, units: array<string, string>, elements: array<string, string>} */
    protected array $curriculum;

    protected string $g2;

    protected function bootGrades(): void
    {
        $this->bootCurriculum();
        $this->curriculum = $this->marie();
        $this->ok('POST', '/higher/curricula/' . $this->curriculum['id'] . '/publish');
        $this->g2 = (string) $this->ok('POST', '/higher/groups', ['level_id' => $this->org['l1'], 'code' => 'G2', 'name' => 'Groupe 2', 'capacity' => 60])['id'];

        $this->fakes = new GradesFakes();
        $this->clock = new GradesClock('2026-10-13');
        $container = $this->kernel->container();
        foreach ([StudentDirectory::class, UserPersonLink::class, FinancialHolds::class, HigherNotifier::class, RecentSecondFactor::class] as $contract) {
            $container->set($contract, $this->fakes);
        }
        $container->set(StaffDirectory::class, new FakeStaff($this->fakes));
        $container->set(AssignmentWriter::class, new FakeAssignments($this->fakes));
        $container->set(GradesClock::class, $this->clock);
        $container->set(PublicationGuard::PREFIX . 'test', [$this->fakes]);

        $this->fakes->enrol('p1', $this->org['l1'], $this->org['g1'], self::YEAR, '26EC-CF-0001');
        $this->fakes->enrol('p2', $this->org['l1'], $this->org['g1'], self::YEAR, '26EC-CF-0002');
        $this->fakes->enrol('p3', $this->org['l1'], $this->g2, self::YEAR, '26EC-CF-0003');
        $this->fakes->link(self::T, self::STUDENT, 'p1');

        $mf = $this->curriculum['elements']['MF'];
        $this->fakes->teacher(self::T, self::TEACHER, 'pt1', $mf, null, self::E);
        $this->fakes->teacher(self::T, self::OTHER_TEACHER, 'pt2', $this->curriculum['elements']['CG'], null, self::E);
        foreach ([self::TEACHER, self::OTHER_TEACHER] as $teacher) {
            foreach (['assessment.enter', 'higher.grades.submit'] as $permission) {
                $this->assign(self::T, $teacher, self::E, $permission, 'structure', $this->org['program'], 'enseignant');
            }
        }
        foreach (['assessment.validate', 'assessment.correction.approve', 'higher.grades.read'] as $permission) {
            $this->assign(self::T, self::CHEF, self::E, $permission, 'org_unit', $this->org['department'], 'higher_chef_dept');
        }
        foreach (['assessment.correction.approve', 'higher.grades.read'] as $permission) {
            $this->assign(self::T, self::DIRECTOR, self::E, $permission, 'org_unit', $this->org['school'], 'higher_dir_ecole');
        }
        foreach (['higher.grades.publish', 'higher.grades.read'] as $permission) {
            $this->assign(self::T, self::REGISTRAR, self::E, $permission, 'org_unit', $this->org['school'], 'higher_scolarite');
        }
        foreach (['higher.anonymity.lift', 'higher.exam.manage', 'higher.grades.read'] as $permission) {
            $this->assign(self::T, self::EXAMS, self::E, $permission, 'org_unit', $this->org['school'], 'higher_examens');
        }
        foreach (['self.read', 'higher.query.create'] as $permission) {
            $this->assign(self::T, self::STUDENT, self::E, $permission, 'establishment', null, 'apprenant');
        }
    }

    protected function tearDown(): void
    {
        unset($this->fakes, $this->clock);
        parent::tearDown();
    }

    /**
     * Évaluation de Mathématiques financières (par défaut : examen, tout le niveau) créée par l'enseignant affecté.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function assessment(string $kind = 'exam', array $extra = [], string $element = 'MF', string $user = self::TEACHER): array
    {
        return $this->ok('POST', '/higher/grade-assessments', [
            'curriculum_id' => $this->curriculum['id'], 'element_id' => $this->curriculum['elements'][$element], 'kind' => $kind,
        ] + $extra, $user);
    }

    /**
     * @param array<string, float|string|null> $values personne => note (float) ou code (string)
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function enter(string $assessmentId, array $values, string $user = self::TEACHER): array
    {
        $marks = [];
        foreach ($values as $person => $value) {
            $marks[] = is_string($value) ? ['person_id' => $person, 'absence' => $value] : ['person_id' => $person, 'score' => $value];
        }

        return $this->call('PUT', '/higher/grade-assessments/' . $assessmentId . '/marks', ['marks' => $marks], $user);
    }

    /** Évaluation complète puis soumise, validée et verrouillée (2FA du chef). @return array<string, mixed> */
    protected function lockedAssessment(float $p1 = 8.0): array
    {
        $a = $this->assessment();
        self::assertSame(200, $this->enter((string) $a['id'], ['p1' => $p1, 'p2' => 12.5, 'p3' => 'ABI'])[0]);
        $this->ok('POST', '/higher/grade-assessments/' . $a['id'] . '/submit', null, self::TEACHER);
        $this->ok('POST', '/higher/grade-assessments/' . $a['id'] . '/validate', null, self::CHEF);
        $this->fakes->recent2fa[self::T . ':' . self::CHEF] = true;

        return $this->ok('POST', '/higher/grade-assessments/' . $a['id'] . '/lock', null, self::CHEF);
    }

    /** @return array<string, mixed> ligne de la grille d'une personne */
    protected function line(string $assessmentId, string $person, string $user = self::CHEF): array
    {
        foreach ($this->ok('GET', '/higher/grade-assessments/' . $assessmentId . '/marks', null, $user)['lines'] as $line) {
            if ($line['person_id'] === $person) {
                return $line;
            }
        }
        self::fail('Ligne absente : ' . $person);
    }
}
