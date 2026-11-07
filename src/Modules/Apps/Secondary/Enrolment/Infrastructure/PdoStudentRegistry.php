<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentProfileInfo;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** Dossiers secondaires des élèves (`se_student_profiles`), client et établissement toujours imposés. */
final class PdoStudentRegistry implements StudentRegistry
{
    public const TABLE = 'se_student_profiles';

    public function __construct(private readonly Store $store, private readonly SecondaryClock $clock)
    {
    }

    /** @return array<string, mixed>|null */
    public function row(string $tenantId, string $establishmentId, string $personId): ?array
    {
        return $this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
    }

    /**
     * Crée le dossier s'il manque, puis applique les changements donnés.
     *
     * @param array<string, scalar|null> $changes
     * @return array<string, mixed>
     */
    public function upsert(string $tenantId, string $establishmentId, string $personId, array $changes = []): array
    {
        $now = $this->clock->now();
        if ($this->row($tenantId, $establishmentId, $personId) === null) {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, ['id' => (string) new Ulid(), 'person_id' => $personId, 'repeater' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($changes !== []) {
            $this->store->update(self::TABLE, $tenantId, $establishmentId, $changes + ['updated_at' => $now], ['person_id' => $personId]);
        }

        return $this->row($tenantId, $establishmentId, $personId) ?? [];
    }

    public function profile(string $tenantId, string $establishmentId, string $personId): ?StudentProfileInfo
    {
        $row = $this->row($tenantId, $establishmentId, $personId);

        return $row === null ? null : new StudentProfileInfo(
            $personId,
            (int) $row['repeater'] === 1,
            is_string($row['final_exclusion_on']) ? $row['final_exclusion_on'] : null,
            is_string($row['readmission_year_id']) ? $row['readmission_year_id'] : null,
        );
    }

    public function markFinalExclusion(string $tenantId, string $establishmentId, string $personId, string $date, string $reference): void
    {
        $this->upsert($tenantId, $establishmentId, $personId, [
            'final_exclusion_on' => $date, 'final_exclusion_ref' => mb_substr($reference, 0, 80), 'readmission_year_id' => null,
            'readmission_reason' => null, 'readmission_decided_by' => null,
        ]);
    }
}
