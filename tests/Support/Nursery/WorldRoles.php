<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;

/** Doublure de RoleDirectory : « client|établissement|rôle » => personnes. */
final class WorldRoles implements RoleDirectory
{
    /** @var array<string, list<string>> */
    private array $holders = [];

    public function assign(string $tenantId, string $establishmentId, string $role, string $personId): void
    {
        $this->holders[$tenantId . '|' . $establishmentId . '|' . $role][] = $personId;
    }

    public function personIdsWithRole(string $tenantId, string $establishmentId, array $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            $out = array_merge($out, $this->holders[$tenantId . '|' . $establishmentId . '|' . $role] ?? []);
        }

        return array_values(array_unique($out));
    }
}
