<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;
use Scholaris\Modules\Apps\Higher\Grades\Domain\MarkValue;

/**
 * Évaluations par EC (EX-NOT-01) projetées sur la maquette publiée (Curriculum) : type (CC, TP, examen, rattrapage), date,
 * barème, poids (40 % / 60 % par défaut), session (normale, rattrapage), anonymat des copies d'examen (EX-NOT-04).
 * La liste des étudiants (inscriptions validées du groupe ou du niveau) est matérialisée en cellules vides à la création et
 * complétée tant que la saisie est ouverte ; une évaluation anonyme donne à chaque copie un numéro d'anonymat.
 */
final class AssessmentService
{
    public const TABLE = 'hi_grade_assessments';
    public const MARKS = 'hi_grade_marks';
    public const KINDS = ['cc', 'tp', 'exam', 'retake'];

    public function __construct(
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly CurriculumDirectory $curricula,
        private readonly AcademicDirectory $academic,
        private readonly StudentDirectory $students,
        private readonly GradesSettings $settings,
        private readonly GradesClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function find(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Évaluation introuvable.');
    }

    /**
     * @param array<string, mixed> $v curriculum_id, element_id, group_id?, kind, label?, held_on?, entry_due_on?, max_score?, weight_percent?, anonymous?
     * @return array<string, mixed>
     */
    public function create(Principal $principal, string $establishmentId, array $v): array
    {
        $tenantId = $principal->tenantId;
        $curriculum = $this->curricula->find($tenantId, $establishmentId, (string) $v['curriculum_id']) ?? throw HttpException::notFound('Maquette introuvable.');
        if ($curriculum->status !== 'published') {
            throw HttpException::conflict('Les évaluations se créent sur une maquette publiée.');
        }
        $element = null;
        $unit = null;
        foreach ($this->curricula->units($tenantId, $establishmentId, $curriculum->id) as $candidate) {
            foreach ($candidate->elements as $e) {
                if ($e['id'] === $v['element_id']) {
                    [$element, $unit] = [$e, $candidate];
                }
            }
        }
        if ($element === null || $unit === null) {
            throw HttpException::validation(['element_id' => ['Élément constitutif absent de cette maquette.']]);
        }
        $program = $this->academic->program($tenantId, $establishmentId, $curriculum->programId) ?? throw HttpException::notFound('Filière introuvable.');
        $groupId = isset($v['group_id']) ? (string) $v['group_id'] : null;
        if ($groupId !== null) {
            $group = $this->academic->group($tenantId, $establishmentId, $groupId);
            if ($group === null || $group->levelId !== $curriculum->levelId) {
                throw HttpException::validation(['group_id' => ['Groupe absent du niveau de la maquette.']]);
            }
        }
        $kind = (string) $v['kind'];
        $schoolId = $program->schoolId;
        $maxScore = isset($v['max_score']) ? (float) $v['max_score'] : IpesDefaults::MAX_SCORE;
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(),
            'school_id' => $schoolId,
            'department_id' => $program->departmentId,
            'program_id' => $program->id,
            'level_id' => $curriculum->levelId,
            'group_id' => $groupId,
            'academic_year_id' => $curriculum->academicYearId,
            'curriculum_id' => $curriculum->id,
            'unit_id' => $unit->id,
            'element_id' => (string) $element['id'],
            'element_code' => (string) $element['code'],
            'element_title' => (string) $element['title'],
            'semester_ordinal' => $unit->semesterOrdinal,
            'kind' => $kind,
            'exam_session' => $kind === 'retake' ? 'retake' : 'normal',
            'label' => isset($v['label']) ? (string) $v['label'] : self::defaultLabel($kind) . ' ' . $element['code'],
            'held_on' => $v['held_on'] ?? null,
            'entry_due_on' => $v['entry_due_on'] ?? null,
            'max_score' => $maxScore,
            'weight_percent' => isset($v['weight_percent']) ? (int) $v['weight_percent'] : $this->settings->defaultWeight($tenantId, $establishmentId, $schoolId, $kind),
            'score_step' => $this->settings->step($tenantId, $establishmentId, $schoolId),
            'anonymous' => ($v['anonymous'] ?? false) === true && in_array($kind, ['exam', 'retake'], true) ? 1 : 0,
            'anonymity_lifted_at' => null,
            'anonymity_lifted_by' => null,
            'status' => $this->settings->lifecycle($tenantId, $establishmentId, $schoolId)->initial(),
            'status_trail' => '[]',
            'version' => 1,
            'created_by' => $principal->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (($v['anonymous'] ?? false) === true && $row['anonymous'] === 0) {
            throw HttpException::validation(['anonymous' => ['L\'anonymat ne s\'applique qu\'aux copies d\'examen et de rattrapage.']]);
        }
        $this->assertMayManage($principal, $establishmentId, $row);
        $this->store->transaction(function () use ($tenantId, $establishmentId, $row): void {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);
            $this->syncRoster($tenantId, $establishmentId, $row);
        });
        $this->policy->audit($principal, 'higher.grades.assessment.created', 'higher_grade_assessment', $row['id'], null, self::resource($row));

        return $this->find($tenantId, $establishmentId, $row['id']);
    }

    /**
     * @param array<string, mixed> $v label, held_on, entry_due_on, weight_percent, max_score
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, string $id, array $v): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->find($tenantId, $establishmentId, $id);
        $this->policy->assertReadable($principal, $establishmentId, $row);
        $this->assertMayManage($principal, $establishmentId, $row);
        if (!$this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id'])->isEditable((string) $row['status'])) {
            throw HttpException::conflict('L\'évaluation n\'est plus en saisie : modification refusée.');
        }
        if (array_key_exists('max_score', $v) && $this->enteredCount($tenantId, $establishmentId, $id) > 0) {
            throw HttpException::conflict('Des notes sont saisies : le barème ne peut plus changer.');
        }
        $v['updated_at'] = $this->clock->now();
        $v['version'] = (int) $row['version'] + 1;
        $this->store->update(self::TABLE, $tenantId, $establishmentId, $v, ['id' => $id]);
        $after = $this->find($tenantId, $establishmentId, $id);
        $this->policy->audit($principal, 'higher.grades.assessment.updated', 'higher_grade_assessment', $id, self::resource($row), self::resource($after));

        return $after;
    }

    /**
     * Évaluations visibles : encadrement dans son périmètre, enseignant pour ses EC et groupes affectés.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function visible(Principal $principal, string $establishmentId, array $filters, ?string $school = null): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->policy->perimeter($principal, $establishmentId, MarkPolicy::READ, $school);
        $isTeacher = $this->policy->can($principal, $establishmentId, MarkPolicy::ENTER);
        if ($perimeter->isEmpty() && !$isTeacher) {
            throw HttpException::forbidden('Permission requise : higher.grades.read ou assessment.enter.');
        }
        $rows = $this->store->select(self::TABLE, $tenantId, $establishmentId, $filters, 'academic_year_id DESC, element_code, kind, created_at', 1000);
        $visible = [];
        foreach ($rows as $row) {
            if ((!$perimeter->isEmpty() && $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::READ[0]))
                || $this->coversAny($principal, $establishmentId, $row)
                || ($isTeacher && $this->policy->teaches($principal, $establishmentId, $row))) {
                $visible[] = $row;
            }
        }

        return $visible;
    }

    /** @param array<string, mixed> $row */
    private function coversAny(Principal $principal, string $establishmentId, array $row): bool
    {
        foreach (array_slice(MarkPolicy::READ, 1) as $permission) {
            if ($this->policy->covers($principal, $establishmentId, $row, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Création et modification : enseignant affecté, chef de département (validation) ou service des examens, dans le périmètre.
     *
     * @param array<string, mixed> $row
     */
    private function assertMayManage(Principal $principal, string $establishmentId, array $row): void
    {
        if ($this->policy->covers($principal, $establishmentId, $row, MarkPolicy::EXAMS) || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::VALIDATE)) {
            return;
        }
        $this->policy->assertTeacher($principal, $establishmentId, $row);
    }

    /**
     * Ajoute une cellule vide pour chaque étudiant inscrit (groupe, ou niveau) qui n'en a pas ; numéro d'anonymat si besoin.
     *
     * @param array<string, mixed> $row
     */
    public function syncRoster(string $tenantId, string $establishmentId, array $row): int
    {
        $structure = $row['group_id'] !== null ? (string) $row['group_id'] : (string) $row['level_id'];
        $existing = [];
        $codes = [];
        foreach ($this->store->select(self::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']], 'id', 5000) as $mark) {
            $existing[(string) $mark['person_id']] = true;
            if ($mark['anonymous_code'] !== null) {
                $codes[(string) $mark['anonymous_code']] = true;
            }
        }
        $added = 0;
        $now = $this->clock->now();
        foreach ($this->students->enrolledIn($tenantId, $establishmentId, $structure, (string) $row['academic_year_id']) as $enrolment) {
            if (isset($existing[$enrolment->personId]) || ($row['group_id'] === null && $enrolment->levelId !== $row['level_id'])) {
                continue;
            }
            $code = null;
            if ((int) $row['anonymous'] === 1) {
                do {
                    $code = 'A' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } while (isset($codes[$code]));
                $codes[$code] = true;
            }
            $this->store->insert(self::MARKS, $tenantId, $establishmentId, [
                'id' => (string) new Ulid(), 'assessment_id' => $row['id'], 'person_id' => $enrolment->personId, 'enrolment_id' => $enrolment->id,
                'group_id' => $enrolment->groupId, 'anonymous_code' => $code, 'score' => null, 'absence_code' => null, 'version' => 0,
                'entered_by' => null, 'entered_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $existing[$enrolment->personId] = true;
            ++$added;
        }

        return $added;
    }

    public function enteredCount(string $tenantId, string $establishmentId, string $assessmentId): int
    {
        $count = 0;
        foreach ($this->store->select(self::MARKS, $tenantId, $establishmentId, ['assessment_id' => $assessmentId], 'id', 5000) as $mark) {
            if ($mark['score'] !== null || $mark['absence_code'] !== null) {
                ++$count;
            }
        }

        return $count;
    }

    private static function defaultLabel(string $kind): string
    {
        return match ($kind) {
            'cc' => 'Contrôle continu',
            'tp' => 'Travaux pratiques',
            'exam' => 'Examen',
            default => 'Rattrapage',
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'school_id' => (string) $row['school_id'],
            'department_id' => (string) $row['department_id'],
            'program_id' => (string) $row['program_id'],
            'level_id' => (string) $row['level_id'],
            'group_id' => $row['group_id'] === null ? null : (string) $row['group_id'],
            'academic_year_id' => (string) $row['academic_year_id'],
            'curriculum_id' => (string) $row['curriculum_id'],
            'unit_id' => (string) $row['unit_id'],
            'element_id' => (string) $row['element_id'],
            'element_code' => (string) $row['element_code'],
            'element_title' => (string) $row['element_title'],
            'semester_ordinal' => (int) $row['semester_ordinal'],
            'kind' => (string) $row['kind'],
            'exam_session' => (string) $row['exam_session'],
            'label' => (string) $row['label'],
            'held_on' => $row['held_on'],
            'entry_due_on' => $row['entry_due_on'],
            'max_score' => (float) $row['max_score'],
            'max_score_label' => MarkValue::format((float) $row['max_score']),
            'weight_percent' => (int) $row['weight_percent'],
            'score_step' => (float) $row['score_step'],
            'anonymous' => (int) $row['anonymous'] === 1,
            'anonymity_lifted' => $row['anonymity_lifted_at'] !== null,
            'status' => (string) $row['status'],
            'status_trail' => json_decode((string) ($row['status_trail'] ?? '[]'), true) ?: [],
            'version' => (int) $row['version'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
