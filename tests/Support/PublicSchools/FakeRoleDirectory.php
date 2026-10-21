<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;

/** Doublure de RoleDirectory : personnes qui exercent un rôle dans un établissement. */
final class FakeRoleDirectory implements RoleDirectory
{
    /** @var array<string, list<string>> */
    private array $holders = [];

    public function add(string $tenantId, string $establishmentId, string $role, string $personId): void
    {
        $this->holders[$tenantId . '|' . $establishmentId . '|' . $role][] = $personId;
    }

    public function personIdsWithRole(string $tenantId, string $establishmentId, array $roles): array
    {
        $ids = [];
        foreach ($roles as $role) {
            array_push($ids, ...($this->holders[$tenantId . '|' . $establishmentId . '|' . $role] ?? []));
        }

        return array_values(array_unique($ids));
    }
}
