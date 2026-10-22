<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Portals\Application;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\LevelDirectory;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\TeachingScope;
use Scholaris\Modules\Socle\Portals\Contracts\GradingTodo;

/**
 * « À remplir » du portail enseignant (EX-PRI-39) : pour chaque classe primaire de l'enseignant, les évaluations de compétence encore
 * manquantes à la période en cours (élèves x compétences du niveau - évaluations saisies). Une feuille complète n'est pas listée.
 * Les classes de l'enseignant sont déduites des inscriptions de ses élèves (contrat TeachingScope).
 */
final class PrimaryGradingTodo implements GradingTodo
{
    private const LIVE = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];

    public function __construct(
        private readonly TeachingScope $scope,
        private readonly EnrollmentDirectory $enrollments,
        private readonly OrgUnitTree $tree,
        private readonly LevelDirectory $levels,
        private readonly CompetencyDirectory $competencies,
        private readonly CurrentPeriod $periods,
        private readonly Store $store,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** Toujours évaluable pour le primaire : une liste (vide si tout est saisi), jamais « non évaluable ». */
    public function pendingFor(string $tenantId, string $teacherPersonId): array
    {
        /** @var array<string, array{establishment: string, year: string, learners: list<string>}> $classes */
        $classes = [];
        foreach ($this->scope->learnerIdsOf($tenantId, $teacherPersonId) as $learnerId) {
            foreach ($this->enrollments->forPerson($tenantId, $learnerId) as $enrollment) {
                if (!in_array($enrollment->status, self::LIVE, true) || $enrollment->structureId === null) {
                    continue;
                }
                $classes[$enrollment->structureId] ??= ['establishment' => $enrollment->establishmentId, 'year' => $enrollment->academicYearId, 'learners' => []];
                $classes[$enrollment->structureId]['learners'][] = $learnerId;
            }
        }
        $items = [];
        foreach ($classes as $classId => $class) {
            $levelId = $this->tree->levelOf($tenantId, $class['establishment'], $classId);
            if ($levelId === null || $this->levels->profile($tenantId, $class['establishment'], $levelId) === null) {
                continue;
            }
            $period = $this->periods->at($tenantId, $class['establishment'], $this->clock->today());
            $structure = $this->tree->structure($tenantId, $class['establishment'], $classId);
            if ($period === null || $structure === null) {
                continue;
            }
            $expected = count($class['learners']) * count($this->competencies->forLevel($tenantId, $class['establishment'], $class['year'], $levelId));
            $entered = $this->store->count('pri_competency_marks', $tenantId, $class['establishment'], ['class_structure_id' => $classId, 'period_id' => $period->id]);
            if ($expected > $entered) {
                $items[] = [
                    'assessment_id' => 'pri-sheet:' . $classId . ':' . $period->id, 'label' => $structure->name, 'establishment_id' => $class['establishment'],
                    'structure_id' => $classId, 'missing_grades' => $expected - $entered,
                ];
            }
        }

        return $items;
    }
}
