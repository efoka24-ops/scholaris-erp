<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use DateTimeImmutable;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\SchedulingStore;

/**
 * Calcule les occurrences d'une journée : créneaux en vigueur, moins les jours fériés et fermetures, avec les
 * modifications ponctuelles appliquées (annulé, déplacé hors de ce jour ou vers ce jour, remplacé).
 */
final class ScheduleResolver
{
    public function __construct(private readonly SchedulingStore $store)
    {
    }

    /**
     * @param string|null $establishmentId null = tous les établissements du client (emploi du temps d'un enseignant)
     * @return list<Occurrence> annulées et déplacées ailleurs comprises (statut), triées par heure
     */
    public function occurrences(string $tenantId, ?string $establishmentId, string $date): array
    {
        $weekday = (int) (new DateTimeImmutable($date))->format('N');
        $slots = [];
        foreach ($this->store->slotsRunningOn($tenantId, $establishmentId, $weekday, $date) as $row) {
            $slots[(string) $row['id']] = $row;
        }

        $changesBySlot = [];
        $movedIn = [];
        foreach ($this->store->changesAround($tenantId, $establishmentId, $date) as $change) {
            if ($change['on_date'] === $date) {
                $changesBySlot[(string) $change['slot_id']] = $change;
            }
            if ($change['kind'] === 'moved' && $change['new_date'] === $date) {
                $movedIn[] = $change;
                if (!isset($slots[(string) $change['slot_id']])) {
                    $row = $this->store->find('timetable_slots', $tenantId, $establishmentId, (string) $change['slot_id']);
                    if ($row !== null) {
                        $slots[(string) $row['id']] = $row;
                    }
                }
            }
        }

        $structures = $this->store->structuresBySlot($tenantId, array_keys($slots));
        $closures = $this->store->closuresOn($tenantId, $establishmentId, $date);

        $out = [];
        foreach ($slots as $id => $slot) {
            $change = $changesBySlot[$id] ?? null;
            $isRunningToday = (int) $slot['weekday'] === $weekday && $slot['valid_from'] <= $date && ($slot['valid_to'] === null || $slot['valid_to'] >= $date);
            if ($isRunningToday) {
                $kept = $this->openStructures($structures[$id] ?? [], $closures, (string) $slot['establishment_id']);
                if ($kept !== []) {
                    $out[] = $this->original($slot, $kept, $date, $change);
                }
            }
        }
        foreach ($movedIn as $change) {
            $slot = $slots[(string) $change['slot_id']] ?? null;
            if ($slot === null) {
                continue;
            }
            $kept = $this->openStructures($structures[(string) $slot['id']] ?? [], $closures, (string) $slot['establishment_id']);
            if ($kept !== []) {
                $out[] = $this->arrival($slot, $kept, $date, $change);
            }
        }
        usort($out, static fn (Occurrence $a, Occurrence $b): int => [$a->startTime, $a->slotId, $a->status] <=> [$b->startTime, $b->slotId, $b->status]);

        return $out;
    }

    /**
     * @param list<string> $structureIds
     * @param list<array<string, mixed>> $closures
     * @return list<string> structures non concernées par un jour férié ou une fermeture
     */
    private function openStructures(array $structureIds, array $closures, string $establishmentId): array
    {
        foreach ($closures as $closure) {
            if ($closure['establishment_id'] !== $establishmentId) {
                continue;
            }
            if ($closure['structure_id'] === null) {
                return [];
            }
            $structureIds = array_values(array_diff($structureIds, [(string) $closure['structure_id']]));
        }

        return $structureIds;
    }

    /**
     * @param array<string, mixed> $slot
     * @param list<string> $structureIds
     * @param array<string, mixed>|null $change
     */
    private function original(array $slot, array $structureIds, string $date, ?array $change): Occurrence
    {
        $status = Occurrence::PLANNED;
        $teacher = (string) $slot['teacher_person_id'];
        $original = null;
        if ($change !== null) {
            if ($change['kind'] === 'cancelled') {
                $status = Occurrence::CANCELLED;
            } elseif ($change['kind'] === 'moved') {
                $status = Occurrence::MOVED_OUT;
            } elseif ($change['kind'] === 'replaced' && $change['new_teacher_person_id'] !== null) {
                $status = Occurrence::REPLACED;
                $original = $teacher;
                $teacher = (string) $change['new_teacher_person_id'];
            }
        }

        return new Occurrence(
            (string) $slot['id'],
            (string) $slot['tenant_id'],
            (string) $slot['establishment_id'],
            $structureIds,
            $teacher,
            (string) $slot['subject_label'],
            $slot['room_id'] === null ? null : (string) $slot['room_id'],
            $slot['course_unit_id'] === null ? null : (string) $slot['course_unit_id'],
            $date,
            (string) $slot['start_time'],
            (string) $slot['end_time'],
            (string) $slot['valid_from'],
            $slot['valid_to'] === null ? null : (string) $slot['valid_to'],
            $status,
            $change === null ? null : (string) $change['id'],
            $original,
            self::kind($slot),
        );
    }

    /**
     * @param array<string, mixed> $slot
     * @param list<string> $structureIds
     * @param array<string, mixed> $change
     */
    private function arrival(array $slot, array $structureIds, string $date, array $change): Occurrence
    {
        $room = $change['new_room_id'] ?? $slot['room_id'];

        return new Occurrence(
            (string) $slot['id'],
            (string) $slot['tenant_id'],
            (string) $slot['establishment_id'],
            $structureIds,
            (string) ($change['new_teacher_person_id'] ?? $slot['teacher_person_id']),
            (string) $slot['subject_label'],
            $room === null ? null : (string) $room,
            $slot['course_unit_id'] === null ? null : (string) $slot['course_unit_id'],
            $date,
            (string) ($change['new_start_time'] ?? $slot['start_time']),
            (string) ($change['new_end_time'] ?? $slot['end_time']),
            (string) $slot['valid_from'],
            $slot['valid_to'] === null ? null : (string) $slot['valid_to'],
            Occurrence::MOVED_IN,
            (string) $change['id'],
            $change['new_teacher_person_id'] === null ? null : (string) $slot['teacher_person_id'],
            self::kind($slot),
        );
    }

    /** @param array<string, mixed> $slot */
    private static function kind(array $slot): ?string
    {
        return isset($slot['session_kind']) ? (string) $slot['session_kind'] : null;
    }
}
