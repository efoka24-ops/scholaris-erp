<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Application\AcademicClock;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Application\CurriculumService;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumSummary;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumUnitInfo;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/** Lecture des maquettes (hi_curricula et contenu) pour les autres sous-modules Higher. */
final class PdoCurriculumDirectory implements CurriculumDirectory
{
    public function __construct(
        private readonly Store $store,
        private readonly CurriculumService $curricula,
        private readonly AcademicClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function publishedFor(string $tenantId, string $establishmentId, string $programId, string $levelId, string $academicYearId): ?CurriculumSummary
    {
        $row = $this->store->select('hi_curricula', $tenantId, $establishmentId, [
            'program_id' => $programId, 'level_id' => $levelId, 'academic_year_id' => $academicYearId, 'status' => 'published',
        ], 'version DESC', 1)[0] ?? null;

        return $row === null ? null : $this->summary($tenantId, $establishmentId, $row);
    }

    public function find(string $tenantId, string $establishmentId, string $curriculumId): ?CurriculumSummary
    {
        $row = $this->store->find('hi_curricula', $tenantId, $establishmentId, $curriculumId);

        return $row === null ? null : $this->summary($tenantId, $establishmentId, $row);
    }

    public function units(string $tenantId, string $establishmentId, string $curriculumId): array
    {
        [$semesters, $units, $elements] = $this->curricula->content($tenantId, $establishmentId, $curriculumId);
        $ordinals = [];
        foreach ($semesters as $semester) {
            $ordinals[(string) $semester['id']] = (int) $semester['ordinal'];
        }
        $byUnit = [];
        foreach ($elements as $element) {
            $e = CurriculumService::element($element);
            $byUnit[$e['unit_id']][] = [
                'id' => $e['id'], 'code' => $e['code'], 'title' => $e['title'], 'credits' => $e['credits'], 'coefficient' => $e['coefficient'],
                'catalog_element_id' => $e['catalog_element_id'],
            ];
        }
        $result = [];
        foreach ($units as $unit) {
            $result[] = new CurriculumUnitInfo(
                (string) $unit['id'],
                $ordinals[(string) $unit['semester_id']] ?? 0,
                (string) $unit['code'],
                (string) $unit['title'],
                (string) $unit['unit_type'],
                (int) $unit['credits'],
                (float) $unit['coefficient'],
                $unit['choice_group'] === null ? null : (string) $unit['choice_group'],
                $unit['choice_count'] === null ? null : (int) $unit['choice_count'],
                $unit['national_exam_paper'] === null ? null : (string) $unit['national_exam_paper'],
                $unit['catalog_subject_id'] === null ? null : (string) $unit['catalog_subject_id'],
                $byUnit[(string) $unit['id']] ?? [],
            );
        }
        usort($result, static fn (CurriculumUnitInfo $a, CurriculumUnitInfo $b): int => $a->semesterOrdinal <=> $b->semesterOrdinal);

        return $result;
    }

    public function markGradesRecorded(string $tenantId, string $establishmentId, string $curriculumId): void
    {
        $row = $this->store->find('hi_curricula', $tenantId, $establishmentId, $curriculumId);
        if ($row === null || $row['grades_recorded_at'] !== null) {
            return;
        }
        $this->store->update('hi_curricula', $tenantId, $establishmentId, ['grades_recorded_at' => $this->clock->now()], ['id' => $curriculumId]);
        $this->audit->append($tenantId, null, 'higher.curriculum.grades_recorded', 'higher_curriculum', $curriculumId, null, ['grades_recorded' => true]);
    }

    /** @param array<string, mixed> $row */
    private function summary(string $tenantId, string $establishmentId, array $row): CurriculumSummary
    {
        return new CurriculumSummary(
            (string) $row['id'],
            (string) $row['program_id'],
            (string) $row['level_id'],
            (string) $row['academic_year_id'],
            (string) $row['regime'],
            (int) $row['version'],
            (string) $row['status'],
            (string) $row['title'],
            $row['approved_programme_ref'] === null ? null : (string) $row['approved_programme_ref'],
            $this->curricula->hasGrades($tenantId, $establishmentId, $row),
        );
    }
}
