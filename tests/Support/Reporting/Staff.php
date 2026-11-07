<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublure de StaffDirectory. */
final class Staff implements StaffDirectory
{
    /** @var array<string, list<string>> */
    private array $teachers = [];

    public function addTeacher(string $tenantId, string $establishmentId, string $personId): void
    {
        $this->teachers[$tenantId . '|' . $establishmentId][] = $personId;
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return $this->teachers[$tenantId . '|' . $establishmentId] ?? [];
    }
}
