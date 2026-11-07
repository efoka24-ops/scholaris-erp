<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublure de StaffDirectory : membres du personnel déclarés (personne => fiche). */
final class InMemoryStaff implements StaffDirectory
{
    /** @var array<string, StaffInfo> */
    private array $members = [];

    public function add(string $tenantId, string $personId, string $staffId): void
    {
        $this->members[$tenantId . '|' . $personId] = new StaffInfo($personId, $tenantId, StaffInfo::PERMANENT, null, 'active', $staffId);
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->members[$tenantId . '|' . $personId] ?? null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return [];
    }
}
