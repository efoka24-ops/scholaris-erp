<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Modules\Apps\Nursery\Sections\Application\SectionService;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Attendance\Contracts\AuthorizedPickupRegistry;
use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Registre du jour (EX-MAT-15, US7) : par section, pour chaque enfant, l'arrivée, la sortie (à qui), les refus, les alertes et
 * « encore à l'école ». Calculé depuis le journal : aucune donnée recopiée. Lecture bornée au périmètre de sections.
 */
final class RegisterService
{
    public function __construct(
        private readonly SectionService $sections,
        private readonly GateJournal $journal,
        private readonly DailyPresence $presence,
        private readonly PersonDirectory $people,
        private readonly AuthorizedPickupRegistry $registry,
        private readonly Store $store,
    ) {
    }

    /** @return array<string, mixed> */
    public function forDay(Perimeter $perimeter, string $day): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $events = [];
        foreach ($this->journal->onDay($tenantId, $establishmentId, $day) as $event) {
            $events[(string) $event['learner_id']][] = $event;
        }
        $alerts = [];
        foreach ($this->store->select(PickupWatch::TABLE, $tenantId, $establishmentId, ['school_day' => $day], 'sent_at, id', 2000) as $alert) {
            $alerts[(string) $alert['learner_id']][] = (string) $alert['alert_level'];
        }
        $absent = array_flip($this->presence->absentOn($tenantId, $establishmentId, $day));
        $sections = [];
        $totals = ['children' => 0, 'present' => 0, 'absent' => 0, 'left' => 0, 'still_at_school' => 0, 'refusals' => 0, 'alerts' => 0];
        foreach ($this->sections->inPerimeter($perimeter) as $section) {
            $ids = $this->sections->childIds($tenantId, (string) $section['id']);
            $names = [];
            foreach ($this->people->findMany($tenantId, $ids) as $person) {
                $names[$person->id] = $person->lastName . ' ' . $person->firstName;
            }
            asort($names);
            $rows = [];
            $pickupNames = $this->pickupNames($tenantId, $establishmentId, $ids, $day);
            foreach ($names as $childId => $name) {
                $row = $this->childRow($childId, $name, $events[$childId] ?? [], $alerts[$childId] ?? [], isset($absent[$childId]), $pickupNames);
                $rows[] = $row;
                ++$totals['children'];
                $row['status'] === 'absent' ? ++$totals['absent'] : null;
                $row['status'] === 'left' ? ++$totals['left'] : null;
                $row['status'] === 'still_at_school' ? ++$totals['still_at_school'] : null;
                $totals['present'] += in_array($row['status'], ['present', 'left', 'still_at_school'], true) ? 1 : 0;
                $totals['refusals'] += $row['refusals'];
                $totals['alerts'] += count($row['alerts']);
            }
            $sections[] = ['id' => $section['id'], 'label' => $section['label'], 'children' => $rows];
        }

        return ['date' => $day, 'totals' => $totals, 'sections' => $sections];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<string> $alerts
     * @param array<string, string> $pickupNames
     * @return array{id: string, name: string, status: string, arrived_at: ?string, left_at: ?string, left_to: ?string, kept: bool, stale: bool, refusals: int, alerts: list<string>}
     */
    private function childRow(string $childId, string $name, array $events, array $alerts, bool $absent, array $pickupNames): array
    {
        $arrived = null;
        $left = null;
        $leftTo = null;
        $kept = false;
        $stale = false;
        $refusals = 0;
        foreach ($events as $event) {
            switch ($event['event_type']) {
                case GateJournal::ARRIVAL:
                    $arrived ??= (string) $event['occurred_at'];
                    break;
                case GateJournal::HANDED_OVER:
                    $left = (string) $event['occurred_at'];
                    $leftTo = (string) ($event['declared_name'] ?? ($pickupNames[(string) $event['pickup_id']] ?? ''));
                    $stale = $stale || (int) $event['stale_authorization'] === 1;
                    break;
                case GateJournal::KEPT:
                    $kept = true;
                    break;
                case GateJournal::REFUSED:
                case GateJournal::CODE_FAILED:
                    ++$refusals;
                    break;
            }
        }
        $status = match (true) {
            $left !== null => 'left',
            $arrived !== null => 'still_at_school',
            $absent => 'absent',
            default => 'expected',
        };

        return ['id' => $childId, 'name' => $name, 'status' => $status, 'arrived_at' => $arrived, 'left_at' => $left, 'left_to' => $leftTo, 'kept' => $kept, 'stale' => $stale, 'refusals' => $refusals, 'alerts' => $alerts];
    }

    /**
     * @param list<string> $learnerIds
     * @return array<string, string> identifiant d'autorisation => nom
     */
    private function pickupNames(string $tenantId, string $establishmentId, array $learnerIds, string $day): array
    {
        $names = [];
        foreach ($this->registry->validOn($tenantId, $establishmentId, $learnerIds, $day) as $info) {
            $names[$info->id] = $info->name;
        }

        return $names;
    }
}
