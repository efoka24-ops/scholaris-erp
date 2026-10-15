<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Teaching\Domain\VolumeConsumption;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\TeachingStore;
use Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences;

/**
 * Consommation des volumes par EC et groupe (EX-EDT-04) : prévues = volumes affectés par nature (CM/TD/TP) ; programmées et réalisées
 * = occurrences de l'emploi du temps du socle (D13 : annulées exclues, reports et remplacements appliqués) pour le groupe ; consignées
 * = séances signées du cahier de textes ; restantes = prévues − réalisées. Réalisées jusqu'à aujourd'hui seulement.
 */
final class ConsumptionService
{
    private const LOGGED = ['signed', 'countersigned', 'validated'];

    public function __construct(
        private readonly TeachingStore $store,
        private readonly TeachingAccess $access,
        private readonly CourseUnitOccurrences $occurrences,
        private readonly TeachingClock $clock,
    ) {
    }

    /**
     * @param array{curriculum_id?: string, element_id?: string, group_id?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function report(Principal $principal, string $establishmentId, array $filters, string $from, string $to, ?string $school): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->require($principal, $establishmentId, TeachingPermissions::TIMETABLE_READ, $school);
        [$sql, $params] = $this->access->sqlFilter($perimeter);
        $where = array_intersect_key($filters, ['curriculum_id' => 1, 'element_id' => 1, 'group_id' => 1]) + ['status' => 'active'];
        $pairs = [];
        foreach ($this->store->select(AssignmentService::TABLE, $tenantId, $establishmentId, $where, 'element_code, group_id, session_kind', 2000, $sql, $params) as $a) {
            $key = $a['element_id'] . '|' . $a['group_id'];
            $pairs[$key] ??= ['element_id' => (string) $a['element_id'], 'element_code' => (string) $a['element_code'], 'element_title' => (string) $a['element_title'],
                'group_id' => (string) $a['group_id'], 'curriculum_id' => (string) $a['curriculum_id'], 'planned' => []];
            $kind = (string) $a['session_kind'];
            $pairs[$key]['planned'][$kind] = ($pairs[$key]['planned'][$kind] ?? 0) + (int) $a['planned_minutes'];
        }
        $today = $this->clock->today();
        $heldTo = min($to, $today);
        $result = [];
        foreach ($pairs as $pair) {
            $scheduled = $held = $logged = [];
            foreach ($this->occurrences->scheduledOf($tenantId, $establishmentId, $pair['element_id'], $from, $to) as $occurrence) {
                if (in_array($pair['group_id'], $occurrence->structureIds, true) && $occurrence->status !== 'cancelled') {
                    $kind = $occurrence->sessionKind ?? 'other';
                    $scheduled[$kind] = ($scheduled[$kind] ?? 0) + $occurrence->minutes;
                }
            }
            if ($heldTo >= $from) {
                foreach ($this->occurrences->heldOf($tenantId, $establishmentId, $pair['element_id'], $from, $heldTo) as $occurrence) {
                    if (in_array($pair['group_id'], $occurrence->structureIds, true)) {
                        $kind = $occurrence->sessionKind ?? 'other';
                        $held[$kind] = ($held[$kind] ?? 0) + $occurrence->minutes;
                    }
                }
            }
            foreach ($this->store->select(SessionLogService::TABLE, $tenantId, $establishmentId, ['element_id' => $pair['element_id'], 'group_id' => $pair['group_id']], 'held_on', 5000) as $log) {
                if (in_array($log['status'], self::LOGGED, true) && $log['held_on'] >= $from && $log['held_on'] <= $to) {
                    $kind = (string) $log['session_kind'];
                    $logged[$kind] = ($logged[$kind] ?? 0) + (int) $log['minutes'];
                }
            }
            $planned = $pair['planned'];
            unset($pair['planned']);
            $result[] = $pair + VolumeConsumption::compute($planned, $scheduled, $held, $logged);
        }

        return $result;
    }
}
