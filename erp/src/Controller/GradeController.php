<?php

declare(strict_types=1);

namespace Scholaris\Controller;

use Scholaris\Database\Table;
use Scholaris\Http\Exception\HttpException;
use Scholaris\Http\Request;
use Scholaris\Http\Response;
use Scholaris\Service\GradeCalculator;

/**
 * Module 5 : saisie des notes et calcul des moyennes.
 *
 * La saisie se fait classe par classe et matiere par matiere, sur toute la
 * liste d'eleves d'un coup : c'est ainsi qu'un enseignant travaille, a partir
 * de son cahier de notes.
 */
final class GradeController extends Controller
{
    private const TYPES = ['TEST', 'HOMEWORK', 'EXAM', 'RESIT'];

    public function index(Request $request): Response
    {
        return $this->view('grades.index', [
            'classrooms' => $this->classrooms(),
            'periods' => $this->periods(),
            'assignments' => $this->assignmentsForCurrentUser(),
            'isTeacher' => ! $this->app->rbac()->allows('grades:calculate'),
        ]);
    }

    /**
     * Grille de saisie : une ligne par eleve de la classe.
     */
    public function entry(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $subjectId = (string) $request->attribute('subject');
        $periodId = (string) $request->attribute('period');

        $classroom = $this->findOrFail('classrooms', $classroomId);
        $subject = $this->findOrFail('subjects', $subjectId);
        $period = $this->periodOrFail($periodId);

        $this->assertMayEnter($classroomId, $subjectId);

        $students = $this->studentsOf($classroomId, $periodId);
        $existing = [];

        foreach ($this->app->db()->select(
            'SELECT * FROM grades
             WHERE tenant_id = :tenant AND subject_id = :subject AND period_id = :period
               AND deleted_at IS NULL AND type = :type',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'subject' => $subjectId,
                'period' => $periodId,
                'type' => $request->string('type', 'TEST'),
            ]
        ) as $grade) {
            $existing[(string) $grade['student_id']] = $grade;
        }

        return $this->view('grades.entry', [
            'classroom' => $classroom,
            'subject' => $subject,
            'period' => $period,
            'students' => $students,
            'existing' => $existing,
            'type' => $request->string('type', 'TEST'),
            'types' => self::TYPES,
            'periodOpen' => $period['grading_status'] === 'OPEN',
        ]);
    }

    public function store(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $subjectId = (string) $request->attribute('subject');
        $periodId = (string) $request->attribute('period');

        $this->findOrFail('classrooms', $classroomId);
        $this->findOrFail('subjects', $subjectId);
        $period = $this->periodOrFail($periodId);

        $this->assertMayEnter($classroomId, $subjectId);

        // La saisie n'est possible que sur une periode ouverte : c'est le
        // verrou pedagogique qui empeche de modifier des notes deja publiees.
        if ($period['grading_status'] !== 'OPEN') {
            return $this->redirectWithError(
                $this->entryUrl($classroomId, $subjectId, $periodId),
                'La periode est fermee : la saisie de notes est impossible.'
            );
        }

        $type = $request->string('type', 'TEST');

        if (! in_array($type, self::TYPES, true)) {
            $type = 'TEST';
        }

        $values = $request->all()['value'] ?? [];
        $absents = $request->all()['absent'] ?? [];

        if (! is_array($values)) {
            $values = [];
        }

        $saved = 0;
        $rejected = 0;
        $teacherId = $this->currentUserId();
        $now = date('Y-m-d H:i:s');

        $this->app->db()->transaction(function () use (
            $values, $absents, $subjectId, $periodId, $type, $teacherId, $now, &$saved, &$rejected
        ): void {
            foreach ($values as $studentId => $raw) {
                if (! is_string($studentId) || ! preg_match('/^[0-9a-f-]{36}$/i', $studentId)) {
                    continue;
                }

                $isAbsent = is_array($absents) && isset($absents[$studentId]);
                $raw = is_scalar($raw) ? trim((string) $raw) : '';

                if (! $isAbsent && $raw === '') {
                    continue;
                }

                $value = null;

                if (! $isAbsent) {
                    $normalized = str_replace(',', '.', $raw);

                    // Une note hors bareme est ignoree plutot qu'ecretee : une
                    // saisie de 45 sur 20 est une faute de frappe, pas un 20.
                    if (! is_numeric($normalized) || (float) $normalized < 0 || (float) $normalized > 20) {
                        $rejected++;

                        continue;
                    }

                    $value = round((float) $normalized, 2);
                }

                $this->upsertGrade($studentId, $subjectId, $periodId, $type, $value, $isAbsent, $teacherId, $now);
                $saved++;
            }
        });

        $message = $saved.' note(s) enregistree(s).';

        if ($rejected > 0) {
            $message .= ' '.$rejected.' valeur(s) hors bareme ignoree(s).';
        }

        return $this->redirectWithSuccess($this->entryUrl($classroomId, $subjectId, $periodId).'?type='.$type, $message);
    }

    /**
     * Lance le calcul des moyennes et du classement d'une classe.
     */
    public function calculate(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $periodId = (string) $request->attribute('period');

        $this->findOrFail('classrooms', $classroomId);
        $this->periodOrFail($periodId);

        $calculator = new GradeCalculator($this->app->db(), $this->app->tenant());
        $result = $calculator->calculate($classroomId, $periodId);

        return $this->redirectWithSuccess(
            '/grades/results/'.$classroomId.'/'.$periodId,
            sprintf('Moyennes calculees pour %d eleve(s) sur %d matiere(s).', $result['students'], $result['subjects'])
        );
    }

    public function results(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $periodId = (string) $request->attribute('period');

        $classroom = $this->findOrFail('classrooms', $classroomId);
        $period = $this->periodOrFail($periodId);

        return $this->view('grades.results', [
            'classroom' => $classroom,
            'period' => $period,
            'results' => $this->app->db()->select(
                'SELECT r.*, s.matricule, s.first_name, s.last_name
                 FROM period_results r INNER JOIN students s ON s.id = r.student_id
                 WHERE r.tenant_id = :tenant AND r.classroom_id = :classroom AND r.period_id = :period
                   AND r.deleted_at IS NULL
                 ORDER BY r.rank_position',
                [
                    'tenant' => $this->app->tenant()->requireId(),
                    'classroom' => $classroomId,
                    'period' => $periodId,
                ]
            ),
        ]);
    }

    /**
     * Publie les resultats : ils deviennent visibles des parents et des eleves.
     */
    public function publish(Request $request): Response
    {
        $classroomId = (string) $request->attribute('classroom');
        $periodId = (string) $request->attribute('period');

        $this->findOrFail('classrooms', $classroomId);
        $this->periodOrFail($periodId);

        $this->table('period_results')
            ->where('classroom_id', $classroomId)
            ->where('period_id', $periodId)
            ->update(['is_published' => 1, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->redirectWithSuccess(
            '/grades/results/'.$classroomId.'/'.$periodId,
            'Resultats publies : ils sont desormais visibles des familles.'
        );
    }

    private function upsertGrade(
        string $studentId,
        string $subjectId,
        string $periodId,
        string $type,
        ?float $value,
        bool $isAbsent,
        string $teacherId,
        string $now
    ): void {
        $existing = $this->app->db()->selectOne(
            'SELECT id, is_locked, value, is_absent FROM grades
             WHERE tenant_id = :tenant AND student_id = :student AND subject_id = :subject
               AND period_id = :period AND type = :type AND deleted_at IS NULL',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'student' => $studentId,
                'subject' => $subjectId,
                'period' => $periodId,
                'type' => $type,
            ]
        );

        // Une note verrouillee ne bouge plus : seul un censeur peut la rouvrir.
        if ($existing !== null && (int) $existing['is_locked'] === 1) {
            return;
        }

        if ($existing !== null) {
            $this->app->db()->execute(
                'UPDATE grades SET value = :value, is_absent = :absent, updated_at = :updated_at WHERE id = :id',
                [
                    'value' => $value,
                    'absent' => $isAbsent ? 1 : 0,
                    'updated_at' => $now,
                    'id' => $existing['id'],
                ]
            );

            // Une note qui change doit pouvoir se contester : savoir qu'elle a
            // ete modifiee ne sert a rien si l'on ignore ce qu'elle valait.
            $this->trail()->changed(
                'grade.update',
                'grades',
                (string) $existing['id'],
                ['value' => $existing['value'], 'is_absent' => (int) $existing['is_absent']],
                ['value' => $value, 'is_absent' => $isAbsent ? 1 : 0],
                ['value', 'is_absent']
            );

            return;
        }

        $id = $this->table('grades')->insert([
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'period_id' => $periodId,
            'teacher_id' => $teacherId,
            'type' => $type,
            'value' => $value,
            'max_value' => 20,
            'weight' => 1,
            'date' => $now,
            'is_absent' => $isAbsent ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->trail()->created('grade.create', 'grades', $id, [
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'type' => $type,
            'value' => $value,
            'is_absent' => $isAbsent ? 1 : 0,
        ]);
    }

    /**
     * Un enseignant ne saisit que dans les classes et matieres qui lui sont
     * affectees. Les profils d'encadrement (censeur, direction) ne sont pas
     * restreints : ils corrigent et arbitrent.
     */
    private function assertMayEnter(string $classroomId, string $subjectId): void
    {
        if ($this->app->rbac()->allows('grades:calculate')) {
            return;
        }

        $assigned = $this->table('subject_assignments')
            ->notDeleted()
            ->where('classroom_id', $classroomId)
            ->where('subject_id', $subjectId)
            ->where('teacher_id', $this->currentUserId())
            ->exists();

        if (! $assigned) {
            throw new HttpException(403, 'Vous n etes pas affecte a cette matiere dans cette classe.');
        }
    }

    private function entryUrl(string $classroomId, string $subjectId, string $periodId): string
    {
        return '/grades/entry/'.$classroomId.'/'.$subjectId.'/'.$periodId;
    }

    /**
     * @return array<string, mixed>
     */
    private function periodOrFail(string $periodId): array
    {
        // Les periodes n'ont pas de tenant_id : le rattachement se verifie via
        // l'annee academique, elle-meme scopee.
        $period = $this->app->db()->selectOne(
            'SELECT p.* FROM periods p
             INNER JOIN academic_years y ON y.id = p.academic_year_id
             WHERE p.id = :id AND y.tenant_id = :tenant',
            ['id' => $periodId, 'tenant' => $this->app->tenant()->requireId()]
        );

        if ($period === null) {
            throw new HttpException(404);
        }

        return $period;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function studentsOf(string $classroomId, string $periodId): array
    {
        return $this->app->db()->select(
            'SELECT s.id, s.matricule, s.first_name, s.last_name
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id
             INNER JOIN periods p ON p.academic_year_id = e.academic_year_id
             WHERE e.tenant_id = :tenant AND e.classroom_id = :classroom AND p.id = :period
               AND e.status = :status AND e.deleted_at IS NULL
             ORDER BY s.last_name, s.first_name',
            [
                'tenant' => $this->app->tenant()->requireId(),
                'classroom' => $classroomId,
                'period' => $periodId,
                'status' => 'ACTIVE',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classrooms(): array
    {
        return $this->app->db()->select(
            'SELECT c.id, c.name, l.name AS level_name FROM classrooms c
             INNER JOIN levels l ON l.id = c.level_id
             WHERE c.tenant_id = :tenant ORDER BY l.sort_order, c.name',
            ['tenant' => $this->app->tenant()->requireId()]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function periods(): array
    {
        return $this->app->db()->select(
            'SELECT p.* FROM periods p
             INNER JOIN academic_years y ON y.id = p.academic_year_id
             WHERE y.tenant_id = :tenant AND y.status = :status
             ORDER BY p.number',
            ['tenant' => $this->app->tenant()->requireId(), 'status' => 'ACTIVE']
        );
    }

    /**
     * Affectations de l'utilisateur connecte, ou toutes pour l'encadrement.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignmentsForCurrentUser(): array
    {
        $sql = 'SELECT a.id, a.classroom_id, a.subject_id, c.name AS classroom_name,
                       s.name AS subject_name, s.coefficient
                FROM subject_assignments a
                INNER JOIN classrooms c ON c.id = a.classroom_id
                INNER JOIN subjects s ON s.id = a.subject_id
                WHERE a.tenant_id = :tenant AND a.deleted_at IS NULL';

        $params = ['tenant' => $this->app->tenant()->requireId()];

        if (! $this->app->rbac()->allows('grades:calculate')) {
            $sql .= ' AND a.teacher_id = :teacher';
            $params['teacher'] = $this->currentUserId();
        }

        return $this->app->db()->select($sql.' ORDER BY c.name, s.name', $params);
    }
}
