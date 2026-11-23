<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Exclusions de cours (T867, Q14) : signalées par l'enseignant pendant l'heure, à côté de l'appel du socle dont les statuts ne
 * changent pas. Rejeu hors ligne sans doublon : la même `client_uuid` rend l'exclusion déjà enregistrée.
 */
final class ClassExclusions
{
    public const TABLE = 'se_class_exclusions';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $roster,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data client_uuid, person_id, class_id, session_date, hour_label, session_id, reason
     * @return array{0: array<string, mixed>, 1: bool} ressource, rejouée
     */
    public function report(Principal $principal, string $establishmentId, Perimeter $perimeter, array $data): array
    {
        $tenantId = $principal->tenantId;
        $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['client_uuid' => (string) $data['client_uuid']], 'id', 1)[0] ?? null;
        if ($existing !== null) {
            $this->access->assertReadableClass($perimeter, (string) $existing['class_id']);

            return [self::resource($existing), true];
        }
        $classId = (string) $data['class_id'];
        $this->roster->assertInClass($tenantId, $establishmentId, (string) $data['person_id'], $classId);
        $id = (string) new Ulid();
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'class_exclusion', $id);
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'person_id' => (string) $data['person_id'], 'class_id' => $classId, 'session_date' => (string) $data['session_date'],
            'hour_label' => (string) $data['hour_label'], 'session_id' => is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
            'reason' => (string) $data['reason'], 'reported_by' => $principal->userId, 'client_uuid' => (string) $data['client_uuid'], 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'secondary.class_exclusion.reported', 'class_exclusion', $id, null, ['person_id' => $data['person_id'], 'class_id' => $classId]);

        return [self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id)), false];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'class_id' => (string) $row['class_id'],
            'session_date' => (string) $row['session_date'], 'hour_label' => (string) $row['hour_label'], 'session_id' => $row['session_id'],
            'reason' => (string) $row['reason'], 'reported_by' => (string) $row['reported_by'], 'client_uuid' => (string) $row['client_uuid'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
