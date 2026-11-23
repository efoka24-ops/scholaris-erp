<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Migration;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublure de l'annuaire du personnel de la reprise. */
final class FakeStaffDirectory implements StaffDirectory
{
    /** @var array<string, StaffInfo> */
    public array $staff = [];

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->staff[$tenantId . ':' . $personId] ?? null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return [];
    }
}
