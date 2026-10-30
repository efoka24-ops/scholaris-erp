<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\ListQuery;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\Messenger;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\Scheduling\Domain\ChangeKind;
use Scholaris\Modules\Socle\Scheduling\Domain\Conflict;
use Scholaris\Modules\Socle\Scheduling\Domain\ConflictDetector;
use Scholaris\Modules\Socle\Scheduling\Domain\SlotPlacement;
use Scholaris\Modules\Socle\Scheduling\Infrastructure\SchedulingStore;
use Throwable;

/**
 * Modifications ponctuelles d'une occurrence (cours annulé, déplacé, remplacé). Chaque modification est vérifiée
 * (conflits sur le nouvel emplacement) puis notifiée à l'enseignant, aux apprenants et à leurs garants.
 * Modèle de message : « timetable_change » (variables subject, date, time, change_label, details, establishment_name).
 */
final class ChangeService
{
    public const TEMPLATE = 'timetable_change';

    public function __construct(
        private readonly SchedulingStore $store,
        private readonly SlotService $slots,
        private readonly ConflictDetector $detector,
        private readonly ScheduleResolver $resolver,
        private readonly Messenger $messenger,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly EstablishmentDirectory $establishments,
        private readonly SchedulingClock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{0: list<array<string, mixed>>, 1: string|null} */
    public function search(string $tenantId, string $establishmentId, ListQuery $query, ?Scope $scope): array
    {
        $conditions = [];
        foreach (['slot_id', 'kind', 'on_date'] as $field) {
            if (isset($query->filters[$field])) {
                $conditions[$field] = $query->filters[$field];
            }
        }
        $sql = '';
        $params = [];
        if ($scope !== null) {
            [$clause, $scopeParams] = SlotService::scopeClause($tenantId, $scope, 'slot_id', 'new_teacher_person_id');
            $sql = '(' . $clause . ' OR slot_id IN (SELECT id FROM timetable_slots WHERE tenant_id = ? AND teacher_person_id = ?))';
            $params = [...$scopeParams, $tenantId, $scope->personId];
        }

        return $this->store->page('timetable_changes', $tenantId, $establishmentId, $conditions, $sql, $params, $query);
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $establishmentId, string $id, ?Scope $scope = null): array
    {
        $row = $this->store->find('timetable_changes', $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Modification introuvable.');
        if ($scope !== null) {
            $slot = $this->slots->get($tenantId, $establishmentId, (string) $row['slot_id']);
            if (!$scope->allows((string) $slot['teacher_person_id'], $slot['structure_ids'], $row['new_teacher_person_id'] === null ? null : (string) $row['new_teacher_person_id'])) {
                throw HttpException::notFound('Modification introuvable.');
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $establishmentId, array $data, string $userId): array
    {
        $slot = $this->slots->get($tenantId, $establishmentId, (string) $data['slot_id']);
        $kind = ChangeKind::from((string) $data['kind']);
        $onDate = (string) $data['on_date'];
        $this->assertOccurrence($slot, $onDate);
        $row = $this->normalise($tenantId, $slot, $kind, $onDate, $data);

        if ($this->store->where('timetable_changes', $tenantId, $establishmentId, ['slot_id' => $slot['id'], 'on_date' => $onDate], 'id', 1) !== []) {
            throw HttpException::conflict('Cette occurrence a déjà été modifiée : supprimez la modification existante avant d\'en créer une autre.');
        }
        $this->assertNoConflict($tenantId, $establishmentId, $slot, $kind, $onDate, $row);

        $id = (string) new Ulid();
        $this->store->insert('timetable_changes', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'establishment_id' => $establishmentId,
            'slot_id' => $slot['id'],
            'on_date' => $onDate,
            'kind' => $kind->value,
            'new_date' => $row['new_date'],
            'new_start_time' => $row['new_start_time'],
            'new_end_time' => $row['new_end_time'],
            'new_room_id' => $row['new_room_id'],
            'new_teacher_person_id' => $row['new_teacher_person_id'],
            'reason' => $data['reason'] ?? null,
            'notified_count' => 0,
            'notified_at' => null,
            'created_by' => $userId,
            'created_at' => $this->clock->now(),
        ]);

        $count = $this->notify($tenantId, $establishmentId, $slot, $kind, $onDate, $row, isset($data['reason']) ? (string) $data['reason'] : null);
        if ($count > 0) {
            $this->store->update('timetable_changes', $tenantId, $establishmentId, $id, ['notified_count' => $count, 'notified_at' => $this->clock->now()]);
        }

        return $this->get($tenantId, $establishmentId, $id);
    }

    /** Annule une modification : l'occurrence reprend son cours normal. */
    public function delete(string $tenantId, string $establishmentId, string $id): void
    {
        $this->get($tenantId, $establishmentId, $id);
        $this->store->delete('timetable_changes', $tenantId, $establishmentId, ['id' => $id]);
    }

    /** @param array<string, mixed> $slot */
    private function assertOccurrence(array $slot, string $onDate): void
    {
        $weekday = (int) (new DateTimeImmutable($onDate))->format('N');
        if ($weekday !== (int) $slot['weekday'] || $onDate < $slot['valid_from'] || ($slot['valid_to'] !== null && $onDate > $slot['valid_to'])) {
            throw HttpException::validation(['on_date' => ['Le créneau n\'a pas lieu à cette date.']]);
        }
    }

    /**
     * Valeurs de la modification selon sa nature ; refuse les champs qui ne s'y appliquent pas.
     *
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $data
     * @return array{new_date: string|null, new_start_time: string|null, new_end_time: string|null, new_room_id: string|null, new_teacher_person_id: string|null}
     */
    private function normalise(string $tenantId, array $slot, ChangeKind $kind, string $onDate, array $data): array
    {
        $row = ['new_date' => null, 'new_start_time' => null, 'new_end_time' => null, 'new_room_id' => null, 'new_teacher_person_id' => null];
        $given = array_values(array_filter(array_keys($row), static fn (string $f): bool => isset($data[$f])));
        $errors = [];
        $allowed = match ($kind) {
            ChangeKind::Cancelled => [],
            ChangeKind::Moved => ['new_date', 'new_start_time', 'new_end_time', 'new_room_id'],
            ChangeKind::Replaced => ['new_teacher_person_id'],
        };
        foreach (array_diff($given, $allowed) as $field) {
            $errors[$field] = ['Ce champ ne s\'applique pas à une modification de type « ' . $kind->value . ' ».'];
        }
        foreach ($allowed as $field) {
            $row[$field] = isset($data[$field]) ? (string) $data[$field] : null;
        }

        if ($kind === ChangeKind::Moved) {
            if ($row['new_date'] === null && $row['new_start_time'] === null && $row['new_end_time'] === null && $row['new_room_id'] === null) {
                $errors['new_date'] = ['Indiquez au moins une nouvelle date, un nouvel horaire ou une nouvelle salle.'];
            }
            $row['new_date'] ??= $onDate;
            if (($row['new_start_time'] ?? (string) $slot['start_time']) >= ($row['new_end_time'] ?? (string) $slot['end_time'])) {
                $errors['new_end_time'] = ['L\'heure de fin doit suivre l\'heure de début.'];
            }
        }
        if ($kind === ChangeKind::Replaced) {
            if ($row['new_teacher_person_id'] === null) {
                $errors['new_teacher_person_id'] = ['Ce champ est obligatoire.'];
            } elseif ($row['new_teacher_person_id'] === (string) $slot['teacher_person_id']) {
                $errors['new_teacher_person_id'] = ['Le remplaçant doit être un autre enseignant.'];
            } elseif ($this->people->find($tenantId, $row['new_teacher_person_id']) === null) {
                $errors['new_teacher_person_id'] = ['Enseignant introuvable.'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $row;
    }

    /**
     * Vérifie le nouvel emplacement (déplacement) ou la disponibilité du remplaçant (remplacement).
     *
     * @param array<string, mixed> $slot
     * @param array<string, string|null> $row
     */
    private function assertNoConflict(string $tenantId, string $establishmentId, array $slot, ChangeKind $kind, string $onDate, array $row): void
    {
        if ($kind === ChangeKind::Cancelled) {
            return;
        }
        $replacement = $kind === ChangeKind::Replaced;
        $date = $replacement ? $onDate : (string) $row['new_date'];
        if (!$replacement && $this->store->closuresOn($tenantId, $establishmentId, $date) !== []) {
            throw HttpException::validation(['new_date' => ['Le nouveau jour est férié ou l\'établissement est fermé.']]);
        }
        $currentRoom = $slot['room_id'] === null ? null : (string) $slot['room_id'];
        $room = $replacement ? $currentRoom : ($row['new_room_id'] ?? $currentRoom);
        $weekday = (int) (new DateTimeImmutable($date))->format('N');
        $candidate = new SlotPlacement(
            (string) $slot['id'],
            (string) ($row['new_teacher_person_id'] ?? $slot['teacher_person_id']),
            $room,
            $slot['structure_ids'],
            $weekday,
            $row['new_start_time'] ?? (string) $slot['start_time'],
            $row['new_end_time'] ?? (string) $slot['end_time'],
            $date,
            $date,
        );
        $others = [];
        foreach ($this->resolver->occurrences($tenantId, $establishmentId, $date) as $occurrence) {
            if ($occurrence->isHeld() && $occurrence->slotId !== $slot['id']) {
                $others[] = new SlotPlacement($occurrence->slotId, $occurrence->teacherPersonId, $occurrence->roomId, $occurrence->structureIds, $weekday, $occurrence->startTime, $occurrence->endTime, $date, $date);
            }
        }
        [$capacity, $headcount] = $this->slots->capacity($tenantId, $establishmentId, $room, $slot['structure_ids']);
        $conflicts = $this->detector->detect($candidate, $others, $capacity, $headcount);
        if ($replacement) {
            $conflicts = array_values(array_filter($conflicts, static fn (Conflict $c): bool => $c->kind === Conflict::TEACHER));
        }
        if ($conflicts !== []) {
            throw new SchedulingConflict($conflicts);
        }
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, string|null> $row
     */
    private function notify(string $tenantId, string $establishmentId, array $slot, ChangeKind $kind, string $onDate, array $row, ?string $reason): int
    {
        $recipients = [(string) $slot['teacher_person_id']];
        if ($row['new_teacher_person_id'] !== null) {
            $recipients[] = $row['new_teacher_person_id'];
        }
        foreach ($slot['structure_ids'] as $structureId) {
            foreach ($this->enrollments->personIdsInStructure($tenantId, (string) $structureId) as $learnerId) {
                $recipients[] = $learnerId;
                foreach ($this->people->guarantorsOf($tenantId, $learnerId) as $guarantor) {
                    $recipients[] = $guarantor->id;
                }
            }
        }

        $details = match ($kind) {
            ChangeKind::Cancelled => '',
            ChangeKind::Moved => sprintf(
                'Nouveau créneau : %s %s-%s%s.',
                $row['new_date'],
                $row['new_start_time'] ?? $slot['start_time'],
                $row['new_end_time'] ?? $slot['end_time'],
                ($row['new_room_id'] ?? null) !== null ? ', salle ' . $row['new_room_id'] : '',
            ),
            ChangeKind::Replaced => 'Remplaçant : ' . ($this->people->find($tenantId, (string) $row['new_teacher_person_id'])?->fullName() ?? '') . '.',
        };
        if ($reason !== null) {
            $details = trim($details . ' Motif : ' . $reason);
        }
        $variables = [
            'subject' => (string) $slot['subject_label'],
            'date' => $onDate,
            'time' => $slot['start_time'] . '-' . $slot['end_time'],
            'change_label' => $kind->label(),
            'details' => $details,
            'establishment_name' => $this->establishments->find($tenantId, $establishmentId)->name ?? '',
        ];

        $sent = 0;
        foreach (array_values(array_unique($recipients)) as $personId) {
            try {
                $result = $this->messenger->send($tenantId, $establishmentId, $personId, self::TEMPLATE, $variables);
                if ($result->status !== DeliveryResult::BLOCKED) {
                    $sent++;
                }
            } catch (Throwable $e) {
                $this->logger->warning('Notification de modification d\'emploi du temps non envoyée.', ['person' => $personId, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
