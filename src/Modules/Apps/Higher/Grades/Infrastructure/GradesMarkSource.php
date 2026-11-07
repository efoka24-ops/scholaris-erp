<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Curriculum\Contracts\CurriculumDirectory;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSheet;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\MarkSource;
use Scholaris\Modules\Apps\Higher\Grades\Application\AssessmentService;
use Scholaris\Modules\Apps\Higher\Grades\Application\GradesSettings;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;

/**
 * Notes saisies dans Higher\Grades offertes à la délibération (EX-DEL-02, RG-DEL-1) : pour un groupe et un semestre « S<n> »,
 * les UE de la maquette publiée et, par EC, les épreuves du groupe (ou de tout le niveau) avec la note de chaque étudiant.
 * Le CC et le TP comptent comme contrôle continu ; l'épreuve de rattrapage est rendue en session `resit` et n'entre que dans la
 * feuille de la session de rattrapage. Les épreuves ni verrouillées ni publiées sont listées dans `unlocked`.
 */
final class GradesMarkSource implements MarkSource
{
    private const LOCKED = [IpesDefaults::LOCKED, IpesDefaults::PUBLISHED];

    public function __construct(
        private readonly Store $store,
        private readonly AcademicDirectory $academic,
        private readonly CurriculumDirectory $curricula,
        private readonly GradesSettings $settings,
    ) {
    }

    public function sheet(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session, ?string $academicYearId = null): ?MarkSheet
    {
        if (preg_match('/^S(\d{1,2})$/i', $periodId, $m) !== 1) {
            return null;
        }
        $semester = (int) $m[1];
        $group = $this->academic->group($tenantId, $establishmentId, $structureId);
        if ($group === null) {
            return null;
        }
        $assessments = array_values(array_filter(
            $this->store->select(AssessmentService::TABLE, $tenantId, $establishmentId, ['level_id' => $group->levelId, 'semester_ordinal' => $semester], 'created_at', 1000),
            static fn (array $a): bool => $a['group_id'] === null || $a['group_id'] === $group->id,
        ));
        $year = $academicYearId ?? $this->latestYear($assessments);
        $assessments = array_values(array_filter($assessments, static fn (array $a): bool => $a['academic_year_id'] === $year));
        if ($assessments === []) {
            return null;
        }
        if ($session !== 'resit') {
            $assessments = array_values(array_filter($assessments, static fn (array $a): bool => $a['kind'] !== 'retake'));
        }

        $curriculumId = (string) $assessments[0]['curriculum_id'];
        $effects = $this->settings->absenceEffects($tenantId, $establishmentId, (string) $assessments[0]['school_id']);
        $byElement = [];
        $unlocked = [];
        $people = [];
        foreach ($assessments as $assessment) {
            if (!in_array($assessment['status'], self::LOCKED, true)) {
                $unlocked[] = $assessment['element_code'] . ' ' . $assessment['label'];
            }
            $kind = match ((string) $assessment['kind']) {
                'exam' => 'exam',
                'retake' => 'resit',
                default => 'cc',
            };
            foreach ($this->store->select(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $assessment['id']], 'id', 5000) as $mark) {
                if ($mark['group_id'] !== null && $mark['group_id'] !== $group->id) {
                    continue;
                }
                $person = (string) $mark['person_id'];
                $people[$person] = true;
                $absence = $mark['absence_code'] === null ? null : (string) $mark['absence_code'];
                $byElement[(string) $assessment['element_id']][$person][] = [
                    'value' => $mark['score'] === null ? null : (float) $mark['score'],
                    'max' => (float) $assessment['max_score'],
                    'weight' => 1.0,
                    'absence' => $absence,
                    'effect' => $absence === null ? null : ($effects[$absence] ?? IpesDefaults::ZERO),
                    'kind' => $kind,
                    'session' => $kind === 'resit' ? 'resit' : 'normal',
                ];
            }
        }

        $units = [];
        foreach (array_keys($people) as $person) {
            $units[$person] = [];
            foreach ($this->curricula->units($tenantId, $establishmentId, $curriculumId) as $unit) {
                if ($unit->semesterOrdinal !== $semester) {
                    continue;
                }
                $groups = [];
                foreach ($unit->elements as $element) {
                    $groups[] = [
                        'element_id' => $element['id'], 'code' => $element['code'], 'coefficient' => $element['coefficient'],
                        'credits' => $element['credits'], 'entries' => $byElement[$element['id']][$person] ?? [],
                    ];
                }
                $units[$person][] = [
                    'subject_id' => $unit->id, 'code' => $unit->code, 'name' => $unit->title, 'kind' => $unit->unitType,
                    'coefficient' => $unit->coefficient, 'credits' => $unit->credits, 'groups' => $groups,
                ];
            }
        }

        return new MarkSheet($units, array_values(array_unique($unlocked)));
    }

    /** @param list<array<string, mixed>> $assessments */
    private function latestYear(array $assessments): ?string
    {
        $years = array_map(static fn (array $a): string => (string) $a['academic_year_id'], $assessments);
        rsort($years);

        return $years[0] ?? null;
    }
}
