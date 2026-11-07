<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Pickup\Contracts\ArrivalJournal;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Store;

/**
 * Journal unique de la journée (`nu_gate_events`) : arrivées, remises, refus, maintiens et essais de code manqués. Il n'est jamais
 * modifié ni supprimé. Un `client_uuid` déjà vu rend l'événement existant (rejeu hors ligne sans doublon).
 */
final class GateJournal implements ArrivalJournal
{
    public const TABLE = 'nu_gate_events';
    public const ARRIVAL = 'arrival';
    public const HANDED_OVER = 'handed_over';
    public const REFUSED = 'refused';
    public const KEPT = 'kept_at_school';
    public const CODE_FAILED = 'code_failed';
    public const ORIGINS = ['online', 'offline'];

    public function __construct(private readonly Store $store, private readonly NurseryClock $clock)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByClientUuid(string $tenantId, string $establishmentId, string $clientUuid): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['client_uuid' => $clientUuid], 'id', 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $fields learner_id, structure_id?, school_day, event_type, pickup_id?, code_id?, declared_name?, restriction_id?, stale?, origin, client_uuid?, occurred_at?, actor_id, remark?
     * @return array<string, mixed> la ligne insérée
     */
    public function append(string $tenantId, string $establishmentId, array $fields): array
    {
        $now = $this->clock->now();
        $row = [
            'id' => (string) new Ulid(),
            'learner_id' => $fields['learner_id'],
            'structure_id' => $fields['structure_id'] ?? null,
            'school_day' => $fields['school_day'],
            'event_type' => $fields['event_type'],
            'pickup_id' => $fields['pickup_id'] ?? null,
            'code_id' => $fields['code_id'] ?? null,
            'declared_name' => $fields['declared_name'] ?? null,
            'restriction_id' => $fields['restriction_id'] ?? null,
            'stale_authorization' => (int) ($fields['stale'] ?? 0),
            'origin' => $fields['origin'] ?? 'online',
            'client_uuid' => $fields['client_uuid'] ?? null,
            'occurred_at' => $fields['occurred_at'] ?? $now,
            'recorded_at' => $now,
            'actor_id' => $fields['actor_id'],
            'remark' => $fields['remark'] ?? null,
        ];
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, $row);

        return ['tenant_id' => $tenantId, 'establishment_id' => $establishmentId] + $row;
    }

    public function recordArrival(string $tenantId, string $establishmentId, string $learnerId, string $sectionId, string $day, string $arrivedAt, string $actorId, string $origin, ?string $clientUuid): bool
    {
        if ($clientUuid !== null && $this->findByClientUuid($tenantId, $establishmentId, $clientUuid) !== null) {
            return false;
        }
        $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'school_day' => $day, 'event_type' => self::ARRIVAL], 'id', 1);
        if ($existing !== []) {
            return false;
        }
        $this->append($tenantId, $establishmentId, [
            'learner_id' => $learnerId, 'structure_id' => $sectionId, 'school_day' => $day, 'event_type' => self::ARRIVAL, 'origin' => $origin,
            'client_uuid' => $clientUuid, 'occurred_at' => $arrivedAt, 'actor_id' => $actorId,
        ]);

        return true;
    }

    /** @return list<array<string, mixed>> événements du jour, dans l'ordre où ils se sont produits */
    public function onDay(string $tenantId, string $establishmentId, string $day): array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['school_day' => $day], 'occurred_at, id', 5000);
    }

    /** @return list<array<string, mixed>> */
    public function forLearnerOnDay(string $tenantId, string $establishmentId, string $learnerId, string $day): array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'school_day' => $day], 'occurred_at, id', 200);
    }

    /** @return list<string> enfants sortis (remis) ou maintenus à l'école ce jour-là : plus de veille pour eux */
    public function settledLearnerIds(string $tenantId, string $establishmentId, string $day): array
    {
        $ids = [];
        foreach ($this->onDay($tenantId, $establishmentId, $day) as $row) {
            if (in_array($row['event_type'], [self::HANDED_OVER, self::KEPT], true)) {
                $ids[] = (string) $row['learner_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> enfants dont l'arrivée est enregistrée ce jour-là */
    public function arrivedLearnerIds(string $tenantId, string $establishmentId, string $day): array
    {
        $ids = [];
        foreach ($this->onDay($tenantId, $establishmentId, $day) as $row) {
            if ($row['event_type'] === self::ARRIVAL) {
                $ids[] = (string) $row['learner_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'learner_id' => (string) $row['learner_id'],
            'section_id' => $row['structure_id'] !== null ? (string) $row['structure_id'] : null,
            'school_day' => (string) $row['school_day'],
            'event_type' => (string) $row['event_type'],
            'pickup_id' => $row['pickup_id'] !== null ? (string) $row['pickup_id'] : null,
            'declared_name' => $row['declared_name'] !== null ? (string) $row['declared_name'] : null,
            'stale_authorization' => (int) $row['stale_authorization'] === 1,
            'origin' => (string) $row['origin'],
            'occurred_at' => (string) $row['occurred_at'],
            'remark' => $row['remark'] !== null ? (string) $row['remark'] : null,
        ];
    }
}
