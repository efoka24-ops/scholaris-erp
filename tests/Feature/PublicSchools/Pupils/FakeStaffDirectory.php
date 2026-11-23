<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublure de l'annuaire du personnel : « client|établissement » => enseignants actifs. */
final class FakeStaffDirectory implements StaffDirectory
{
    /** @var array<string, list<string>> */
    private array $teachers = [];

    /** @param list<string> $personIds */
    public function set(string $tenantId, string $establishmentId, array $personIds): void
    {
        $this->teachers[$tenantId . '|' . $establishmentId] = $personIds;
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        foreach ($this->teachers as $key => $ids) {
            if (str_starts_with($key, $tenantId . '|') && in_array($personId, $ids, true)) {
                return new StaffInfo($personId, $tenantId, StaffInfo::PERMANENT, null, 'active');
            }
        }

        return null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return $this->teachers[$tenantId . '|' . $establishmentId] ?? [];
    }
}
