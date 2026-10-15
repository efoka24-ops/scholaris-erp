<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Identity\Domain\Role;

/** Rôles standards et permissions qu'ils portent (tables de référence `roles` et `role_permissions`). */
final class PdoRoleCatalog extends PdoRepository
{
    /** @return array<string, Role> */
    public function all(): array
    {
        $roles = [];
        foreach ($this->loadRoles() as $role) {
            $roles[$role->code] = $role;
        }

        return $roles;
    }

    public function find(string $code): ?Role
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * @param list<string> $roleCodes
     * @return list<string> permissions distinctes portées par ces rôles
     */
    public function permissionsOf(array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($roleCodes), '?'));
        $rows = $this->rows('SELECT DISTINCT permission FROM role_permissions WHERE role IN (' . $marks . ') ORDER BY permission', $roleCodes);

        return array_map(static fn (array $row): string => (string) $row['permission'], $rows);
    }

    /** @return list<Role> */
    private function loadRoles(): array
    {
        return array_map(
            static fn (array $row): Role => new Role(
                (string) $row['code'],
                (string) $row['level'],
                (int) $row['is_sensitive'] === 1,
                (string) $row['label_fr'],
                (string) $row['label_en'],
            ),
            $this->rows('SELECT * FROM roles ORDER BY code', []),
        );
    }
}
