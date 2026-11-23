<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Grades\Contracts\SheetProgress;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/**
 * Suivi de saisie des feuilles de compétences d'une classe (EX-PRI-43). Aucun seuil dans le code :
 *  - « en retard » à la période en cours = feuille ouverte, incomplète, ET date limite de la période dépassée ; les dates limites
 *    sont un paramètre de l'école, `primary.sheets.entry_deadlines` ({identifiant de période: AAAA-MM-JJ}), sans date : jamais « en retard » ;
 *  - une période terminée dont la feuille a des évaluations mais n'est pas verrouillée est « à terminer » (retard sans paramètre).
 */
final class SheetFollowUp
{
    public const DEADLINES_KEY = 'primary.sheets.entry_deadlines';
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /** @var array<string, int> */
    private array $competencyCounts = [];

    public function __construct(
        private readonly SheetProgress $progress,
        private readonly CompetencyDirectory $competencies,
        private readonly CurrentPeriod $periods,
        private readonly SettingsReader $settings,
    ) {
    }

    /** @return array{period: ?PeriodInfo, current_ids: list<string>, deadlines: array<string, string>, today: string} */
    public function context(string $tenantId, string $establishmentId, string $today): array
    {
        $deadlines = [];
        $raw = $this->settings->value($tenantId, $establishmentId, self::DEADLINES_KEY, []);
        foreach (is_array($raw) ? $raw : [] as $periodId => $date) {
            if (is_string($date) && preg_match(self::DATE, $date) === 1) {
                $deadlines[(string) $periodId] = $date;
            }
        }

        return [
            'period' => $this->periods->at($tenantId, $establishmentId, $today),
            'current_ids' => array_map(static fn (PeriodInfo $p): string => $p->id, $this->periods->allAt($tenantId, $establishmentId, $today)),
            'deadlines' => $deadlines,
            'today' => $today,
        ];
    }

    /**
     * @param array{period: ?PeriodInfo, current_ids: list<string>, deadlines: array<string, string>, today: string} $context
     * @return array{current: ?array{period_id: string, expected: int, entered: int, status: string, deadline: ?string, late: bool}, overdue_periods: list<string>, late: bool}
     */
    public function forClass(string $tenantId, string $establishmentId, ClassFigure $class, array $context): array
    {
        $marks = $this->progress->marksByPeriod($tenantId, $establishmentId, $class->id);
        $states = $this->progress->statesByPeriod($tenantId, $establishmentId, $class->id);

        $current = null;
        $period = $context['period'];
        if ($period !== null) {
            $expected = count($class->learnerIds) * $this->competencyCount($tenantId, $establishmentId, $period->academicYearId, $class->levelId);
            $entered = $marks[$period->id] ?? 0;
            $status = $states[$period->id] ?? 'open';
            $deadline = $context['deadlines'][$period->id] ?? null;
            $current = [
                'period_id' => $period->id, 'expected' => $expected, 'entered' => $entered, 'status' => $status, 'deadline' => $deadline,
                'late' => $status === 'open' && $expected > $entered && $deadline !== null && $context['today'] > $deadline,
            ];
        }

        $overdue = [];
        foreach ($marks as $periodId => $count) {
            if ($count > 0 && !in_array($periodId, $context['current_ids'], true) && ($states[$periodId] ?? 'open') === 'open') {
                $overdue[] = $periodId;
            }
        }
        sort($overdue);

        return ['current' => $current, 'overdue_periods' => $overdue, 'late' => ($current !== null && $current['late']) || $overdue !== []];
    }

    private function competencyCount(string $tenantId, string $establishmentId, string $yearId, string $levelId): int
    {
        $key = $tenantId . '|' . $establishmentId . '|' . $yearId . '|' . $levelId;

        return $this->competencyCounts[$key] ??= count($this->competencies->forLevel($tenantId, $establishmentId, $yearId, $levelId));
    }
}
