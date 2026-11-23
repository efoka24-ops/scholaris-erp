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
     * @return list<string> permissions distinctes portées par ces rôles ; un rôle à agrégats seuls (PP2) ne porte aucune
     *                      permission nominative `*.named.*`
     */
    public function permissionsOf(array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($roleCodes), '?'));
        $rows = $this->rows(
            'SELECT DISTINCT rp.permission FROM role_permissions rp LEFT JOIN roles r ON r.code = rp.role '
            . 'WHERE rp.role IN (' . $marks . ") AND (COALESCE(r.aggregate_only, 0) = 0 OR rp.permission NOT LIKE '%.named.%') "
            . 'ORDER BY rp.permission',
            $roleCodes,
        );

        return array_map(static fn (array $row): string => (string) $row['permission'], $rows);
    }

    /**
     * Permissions de chaque rôle demandé, en une seule requête (mêmes règles que permissionsOf).
     *
     * @param list<string> $roleCodes
     * @return array<string, list<string>>
     */
    public function permissionsByRole(array $roleCodes): array
    {
        $byRole = array_fill_keys($roleCodes, []);
        if ($roleCodes === []) {
            return $byRole;
        }
        $marks = implode(', ', array_fill(0, count($roleCodes), '?'));
        $rows = $this->rows(
            'SELECT DISTINCT rp.role, rp.permission FROM role_permissions rp LEFT JOIN roles r ON r.code = rp.role '
            . 'WHERE rp.role IN (' . $marks . ") AND (COALESCE(r.aggregate_only, 0) = 0 OR rp.permission NOT LIKE '%.named.%') "
            . 'ORDER BY rp.role, rp.permission',
            $roleCodes,
        );
        foreach ($rows as $row) {
            $byRole[(string) $row['role']][] = (string) $row['permission'];
        }

        return $byRole;
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
                (int) ($row['aggregate_only'] ?? 0) === 1,
                isset($row['max_assignment_days']) ? (int) $row['max_assignment_days'] : null,
            ),
            $this->rows('SELECT * FROM roles ORDER BY code', []),
        );
    }
}
