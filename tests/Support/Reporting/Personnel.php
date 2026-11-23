<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Identity\Contracts\EstablishmentPersonnel;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;

/** Doublure d'EstablishmentPersonnel (rôles internes) et d'EstablishmentStaffRoster (fiches du personnel). */
final class Personnel implements EstablishmentPersonnel, EstablishmentStaffRoster
{
    /** @var array<string, list<string>> */
    private array $withRole = [];

    /** @var array<string, list<string>> */
    private array $members = [];

    public function withRole(string $tenantId, string $establishmentId, string $personId): void
    {
        $this->withRole[$tenantId . '|' . $establishmentId][] = $personId;
    }

    public function member(string $tenantId, string $establishmentId, string $personId): void
    {
        $this->members[$tenantId . '|' . $establishmentId][] = $personId;
    }

    public function personnelAmong(string $tenantId, string $establishmentId, array $personIds): array
    {
        return array_values(array_intersect($personIds, $this->withRole[$tenantId . '|' . $establishmentId] ?? []));
    }

    public function membersOf(string $tenantId, string $establishmentId): array
    {
        return array_map(
            static fn (string $personId): StaffMemberInfo => new StaffMemberInfo('s-' . $personId, $personId, $tenantId, 'permanent', 'active', null, null, true),
            $this->members[$tenantId . '|' . $establishmentId] ?? [],
        );
    }
}
