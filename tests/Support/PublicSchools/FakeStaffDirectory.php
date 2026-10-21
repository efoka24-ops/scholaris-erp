<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublure de StaffDirectory : enseignants par établissement (personne, fiche, grade). */
final class FakeStaffDirectory implements StaffDirectory
{
    /** @var array<string, StaffInfo> */
    private array $members = [];

    /** @var array<string, list<string>> */
    private array $byEstablishment = [];

    public function add(string $tenantId, string $establishmentId, string $personId, string $staffId, ?string $grade = null): void
    {
        $this->members[$tenantId . '|' . $personId] = new StaffInfo($personId, $tenantId, StaffInfo::PERMANENT, $grade, 'active', $staffId);
        $this->byEstablishment[$tenantId . '|' . $establishmentId][] = $personId;
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->members[$tenantId . '|' . $personId] ?? null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return $this->byEstablishment[$tenantId . '|' . $establishmentId] ?? [];
    }
}
