<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Scholaris\Modules\Socle\People\Infrastructure\HealthCipher;
use Scholaris\Modules\Socle\People\Infrastructure\PdoStore;

/** Dossier de santé : un seul bloc JSON chiffré par personne ; aucune valeur lisible n'est écrite en base. */
final class HealthService implements FoodAllergies
{
    public const FIELDS = ['blood_group', 'allergies', 'conditions', 'medications', 'doctor_name', 'doctor_phone', 'notes'];

    public function __construct(
        private readonly PdoStore $store,
        private readonly HealthCipher $cipher,
        private readonly PeopleClock $clock,
    ) {
    }

    /** @return array<string, string|null> */
    public function read(string $tenantId, string $personId): array
    {
        $empty = array_fill_keys(self::FIELDS, null);
        $row = $this->store->where('health_records', $tenantId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        if ($row === null) {
            return $empty;
        }
        $data = json_decode($this->cipher->decrypt((string) $row['payload_enc']), true);

        return is_array($data) ? array_intersect_key($data + $empty, $empty) : $empty;
    }

    /**
     * @param list<string> $learnerIds
     * @return array<string, string|null>
     */
    public function forLearners(string $tenantId, array $learnerIds): array
    {
        $out = [];
        foreach ($learnerIds as $learnerId) {
            $out[$learnerId] = $this->read($tenantId, $learnerId)['allergies'];
        }

        return $out;
    }

    public function declare(string $tenantId, string $learnerId, ?string $text, string $actorId): void
    {
        $current = $this->read($tenantId, $learnerId);
        $current['allergies'] = $text;
        $this->write($tenantId, $learnerId, $current, $actorId);
    }

    /**
     * @param array<string, string|null> $values
     * @return array<string, string|null>
     */
    public function write(string $tenantId, string $personId, array $values, ?string $actorId): array
    {
        $merged = array_intersect_key($values + $this->read($tenantId, $personId), array_flip(self::FIELDS));
        $payload = $this->cipher->encrypt(json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $now = $this->clock->now();
        $existing = $this->store->where('health_records', $tenantId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        if ($existing === null) {
            $this->store->insert('health_records', [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'person_id' => $personId,
                'payload_enc' => $payload, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $this->store->update('health_records', $tenantId, (string) $existing['id'], ['payload_enc' => $payload, 'updated_by' => $actorId, 'updated_at' => $now]);
        }

        return $merged;
    }
}
