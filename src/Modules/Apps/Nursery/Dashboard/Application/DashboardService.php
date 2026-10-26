<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Dashboard\Application;

use Scholaris\Modules\Apps\Nursery\Development\Application\ObservationService;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\GateJournal;
use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupWatch;
use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Services\Application\ServiceAttendance;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;

/**
 * Tableau de bord de la direction et du promoteur (EX-MAT-32) : effectifs par section (et capacité), absents et arrivées du jour,
 * retards de récupération (alertes du jour et du mois), impayés (nombre d'enfants et solde total), observations en retard (enfants
 * et domaines sous le minimum du mois, `nursery.dev.min_observations_per_domain_per_month`), présences aux services. AGRÉGATS
 * seulement : aucun nom d'enfant, aucune donnée nominative (le promoteur y a accès).
 */
final class DashboardService
{
    public function __construct(
        private readonly SectionService $sections,
        private readonly DailyPresence $presence,
        private readonly GateJournal $journal,
        private readonly DebtorDirectory $debtors,
        private readonly ObservationService $observations,
        private readonly ServiceAttendance $services,
        private readonly Store $store,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(string $tenantId, string $establishmentId, string $day): array
    {
        $whole = new Perimeter($tenantId, $establishmentId, true);
        $month = substr($day, 0, 7);
        $absent = array_flip($this->presence->absentOn($tenantId, $establishmentId, $day));
        $arrived = array_flip($this->journal->arrivedLearnerIds($tenantId, $establishmentId, $day));
        $arrears = array_flip($this->debtors->learnerIdsInArrears($tenantId, $establishmentId));
        $sections = [];
        $totals = ['enrolled' => 0, 'absent' => 0, 'arrived' => 0, 'in_arrears' => 0, 'balance_xaf' => 0, 'observation_gaps' => 0, 'over_capacity_sections' => 0];
        foreach ($this->sections->inPerimeter($whole) as $section) {
            $ids = $this->sections->childIds($tenantId, (string) $section['id']);
            $row = [
                'id' => (string) $section['id'], 'label' => (string) $section['label'], 'enrolled' => count($ids), 'capacity' => $section['capacity'],
                'absent' => 0, 'arrived' => 0, 'in_arrears' => 0, 'observation_gaps' => $this->observations->gapCount($tenantId, $establishmentId, (string) $section['id'], $month),
            ];
            foreach ($ids as $id) {
                $row['absent'] += isset($absent[$id]) ? 1 : 0;
                $row['arrived'] += isset($arrived[$id]) ? 1 : 0;
                if (isset($arrears[$id])) {
                    ++$row['in_arrears'];
                    $totals['balance_xaf'] += $this->debtors->balanceXaf($tenantId, $establishmentId, $id);
                }
            }
            $row['over_capacity'] = is_int($section['capacity']) && $row['enrolled'] > $section['capacity'];
            foreach (['enrolled', 'absent', 'arrived', 'in_arrears', 'observation_gaps'] as $key) {
                $totals[$key] += $row[$key];
            }
            $totals['over_capacity_sections'] += $row['over_capacity'] ? 1 : 0;
            $sections[] = $row;
        }

        return [
            'date' => $day,
            'totals' => $totals,
            'sections' => $sections,
            'late_pickups' => [
                'today' => $this->alerts($tenantId, $establishmentId, $day, $day),
                'month' => $this->alerts($tenantId, $establishmentId, $month . '-01', $day),
            ],
            'services_present_today' => $this->services->presentOn($tenantId, $establishmentId, $day),
        ];
    }

    /** @return array{parent: int, director: int} alertes de non-récupération émises entre deux jours inclus */
    private function alerts(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $counts = ['parent' => 0, 'director' => 0];
        foreach ($this->store->select(PickupWatch::TABLE, $tenantId, $establishmentId, [], 'school_day, id', 20000, 'school_day >= ? AND school_day <= ?', [$from, $to]) as $row) {
            $level = (string) $row['alert_level'];
            if (isset($counts[$level])) {
                ++$counts[$level];
            }
        }

        return $counts;
    }
}
