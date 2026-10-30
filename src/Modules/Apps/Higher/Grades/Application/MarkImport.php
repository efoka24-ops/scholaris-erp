<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\MarkImporter;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;
use Scholaris\Modules\Apps\Higher\Grades\Domain\MarkValue;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Notes reprises (MarkImporter) : évaluations « verrouillées » avec événements « reprise », mêmes tables que la saisie courante. */
final class MarkImport implements MarkImporter
{
    private const KINDS = ['cc', 'tp', 'exam'];

    public function __construct(
        private readonly Store $store,
        private readonly AcademicDirectory $academic,
        private readonly CurriculumDirectory $curricula,
        private readonly GradesSettings $settings,
        private readonly GradesClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function checkTarget(string $tenantId, string $establishmentId, string $programId, string $levelId, ?string $groupId, string $academicYearId, string $elementCode, string $kind): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return 'Type d\'évaluation inconnu : cc, tp ou exam.';
        }
        if ($groupId !== null) {
            $group = $this->academic->group($tenantId, $establishmentId, $groupId);
            if ($group === null || $group->levelId !== $levelId) {
                return 'Le groupe n\'appartient pas au niveau indiqué.';
            }
        }
        $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $programId, $levelId, $academicYearId);
        if ($curriculum === null) {
            return 'Aucune maquette publiée pour cette filière, ce niveau et cette année.';
        }

        return $this->locate($tenantId, $establishmentId, $curriculum->id, $elementCode) === null ? 'EC « ' . $elementCode . ' » absent de la maquette publiée.' : null;
    }

    public function normalize(string $tenantId, string $establishmentId, string $programId, string $raw): array
    {
        $program = $this->academic->program($tenantId, $establishmentId, $programId);
        $schoolId = $program?->schoolId;
        try {
            $value = MarkValue::fromCsv(
                $raw,
                IpesDefaults::MAX_SCORE,
                $this->settings->step($tenantId, $establishmentId, $schoolId),
                $this->settings->absenceCodes($tenantId, $establishmentId, $schoolId),
            );
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation(['score' => [$e->getMessage()]]);
        }

        return ['score' => $value->score, 'absence' => $value->absence];
    }

    public function importAssessment(string $tenantId, string $establishmentId, string $actorId, string $programId, string $levelId, ?string $groupId, string $academicYearId, string $elementCode, string $kind, array $marks, string $reason): array
    {
        $refusal = $this->checkTarget($tenantId, $establishmentId, $programId, $levelId, $groupId, $academicYearId, $elementCode, $kind);
        if ($refusal !== null) {
            throw HttpException::validation(['assessment' => [$refusal]]);
        }
        $curriculum = $this->curricula->publishedFor($tenantId, $establishmentId, $programId, $levelId, $academicYearId) ?? throw HttpException::notFound('Maquette introuvable.');
        [$unit, $element] = $this->locate($tenantId, $establishmentId, $curriculum->id, $elementCode) ?? throw HttpException::notFound('EC introuvable.');
        $program = $this->academic->program($tenantId, $establishmentId, $programId) ?? throw HttpException::notFound('Filière introuvable.');
        $label = 'Reprise · ' . strtoupper($kind) . ' · ' . $element['code'];
        $existing = $this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, [
            'curriculum_id' => $curriculum->id, 'element_id' => $element['id'], 'group_id' => $groupId, 'kind' => $kind, 'label' => $label,
        ], 'id', 1)[0] ?? null;
        if ($existing !== null) {
            return ['assessment_id' => (string) $existing['id'], 'created' => false, 'written' => 0];
        }
        $states = $this->settings->lifecycle($tenantId, $establishmentId, $program->schoolId)->states;
        $locked = in_array(IpesDefaults::LOCKED, $states, true) ? IpesDefaults::LOCKED : $states[count($states) - 2] ?? $states[0];

        return $this->store->transaction(function () use ($tenantId, $establishmentId, $actorId, $program, $levelId, $groupId, $academicYearId, $curriculum, $unit, $element, $kind, $label, $marks, $reason, $locked): array {
            $id = (string) new Ulid();
            $now = $this->clock->now();
            $this->store->insert(AssessmentService::TABLE, $tenantId, $establishmentId, [
                'id' => $id, 'school_id' => $program->schoolId, 'department_id' => $program->departmentId, 'program_id' => $program->id, 'level_id' => $levelId,
                'group_id' => $groupId, 'academic_year_id' => $academicYearId, 'curriculum_id' => $curriculum->id, 'unit_id' => $unit,
                'element_id' => $element['id'], 'element_code' => $element['code'], 'element_title' => mb_substr($element['title'], 0, 160),
                'semester_ordinal' => $element['semester'], 'kind' => $kind, 'exam_session' => 'normal', 'label' => $label, 'held_on' => null, 'entry_due_on' => null,
                'max_score' => IpesDefaults::MAX_SCORE, 'weight_percent' => $this->settings->defaultWeight($tenantId, $establishmentId, $program->schoolId, $kind),
                'score_step' => $this->settings->step($tenantId, $establishmentId, $program->schoolId), 'anonymous' => 0, 'anonymity_lifted_at' => null,
                'anonymity_lifted_by' => null, 'status' => $locked, 'status_trail' => (string) json_encode([['from' => 'draft', 'to' => $locked, 'by' => $actorId, 'at' => $now, 'reason' => $reason]]),
                'version' => 1, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $written = 0;
            foreach ($marks as $mark) {
                $markId = (string) new Ulid();
                $this->store->insert(AssessmentService::MARKS, $tenantId, $establishmentId, [
                    'id' => $markId, 'assessment_id' => $id, 'person_id' => $mark['person_id'], 'enrolment_id' => $mark['enrolment_id'], 'group_id' => $groupId,
                    'anonymous_code' => null, 'score' => $mark['score'], 'absence_code' => $mark['absence'], 'version' => 1, 'entered_by' => $actorId,
                    'entered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->store->insert(MarkService::EVENTS, $tenantId, $establishmentId, [
                    'id' => (string) new Ulid(), 'mark_id' => $markId, 'assessment_id' => $id, 'person_id' => $mark['person_id'], 'event_kind' => self::EVENT,
                    'old_score' => null, 'old_absence' => null, 'new_score' => $mark['score'], 'new_absence' => $mark['absence'], 'mark_version' => 1,
                    'actor_id' => $actorId, 'reason' => mb_substr($reason, 0, 500), 'correction_id' => null, 'client_uuid' => null, 'created_at' => $now,
                ]);
                ++$written;
            }
            $this->curricula->markGradesRecorded($tenantId, $establishmentId, $curriculum->id);
            $this->audit->append($tenantId, $actorId, 'higher.grades.assessment.imported', 'higher_grade_assessment', $id, null, ['label' => $label, 'status' => $locked, 'marks' => $written, 'reason' => $reason]);

            return ['assessment_id' => $id, 'created' => true, 'written' => $written];
        });
    }

    public function revertAssessment(string $tenantId, string $establishmentId, string $actorId, string $assessmentId): void
    {
        $row = $this->store->find(AssessmentService::TABLE, $tenantId, $establishmentId, $assessmentId);
        if ($row === null) {
            return;
        }
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id']);
        if ($lifecycle->isPublished((string) $row['status'])) {
            throw HttpException::conflict('Annulation impossible : les notes de « ' . $row['label'] . ' » sont publiées.');
        }
        if ($this->store->count('hi_grade_corrections', $tenantId, $establishmentId, ['assessment_id' => $assessmentId]) > 0) {
            throw HttpException::conflict('Annulation impossible : une correction de note dépend de « ' . $row['label'] . ' ».');
        }
        $this->store->transaction(function () use ($tenantId, $establishmentId, $actorId, $assessmentId, $row): void {
            $this->store->delete(MarkService::EVENTS, $tenantId, $establishmentId, ['assessment_id' => $assessmentId]);
            $this->store->delete(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $assessmentId]);
            $this->store->delete(AssessmentService::TABLE, $tenantId, $establishmentId, ['id' => $assessmentId]);
            $this->audit->append($tenantId, $actorId, 'higher.grades.assessment.import_reverted', 'higher_grade_assessment', $assessmentId, ['label' => $row['label']], null);
        });
    }

    /** @return array{0: string, 1: array{id: string, code: string, title: string, semester: int}}|null identifiant de l'UE et EC */
    private function locate(string $tenantId, string $establishmentId, string $curriculumId, string $code): ?array
    {
        $wanted = strtoupper(trim($code));
        foreach ($this->curricula->units($tenantId, $establishmentId, $curriculumId) as $unit) {
            foreach ($unit->elements as $element) {
                if (strtoupper($element['code']) === $wanted) {
                    return [$unit->id, ['id' => $element['id'], 'code' => $element['code'], 'title' => $element['title'], 'semester' => $unit->semesterOrdinal]];
                }
            }
        }

        return null;
    }
}
