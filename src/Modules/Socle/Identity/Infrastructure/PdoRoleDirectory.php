<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;

/** Personnes titulaires d'un rôle dans un établissement, d'après les affectations de rôle en vigueur. */
final class PdoRoleDirectory extends PdoRepository implements RoleDirectory
{
    public function __construct(ConnectionFactory $connections, private readonly Moment $moment)
    {
        parent::__construct($connections);
    }

    public function personIdsWithRole(string $tenantId, string $establishmentId, array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $now = $this->moment->iso();
        $rows = $this->rows(
            'SELECT DISTINCT person_id FROM role_assignments
             WHERE tenant_id = ? AND role IN (' . implode(', ', array_fill(0, count($roles), '?')) . ')
               AND (scope_type = ? OR establishment_id = ?) AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)
             ORDER BY person_id',
            [$tenantId, ...$roles, 'tenant', $establishmentId, $now, $now],
        );

        return array_map(static fn (array $row): string => (string) $row['person_id'], $rows);
    }
}
