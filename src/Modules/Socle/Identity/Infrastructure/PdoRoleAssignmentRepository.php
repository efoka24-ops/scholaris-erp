<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Identity\Domain\RoleAssignment;
use Scholaris\Modules\Socle\Identity\Domain\ScopeType;

final class PdoRoleAssignmentRepository extends PdoRepository
{
    public function insert(RoleAssignment $a): void
    {
        $this->run(
            'INSERT INTO role_assignments (id, tenant_id, person_id, role, scope_type, scope_id, establishment_id, starts_at, ends_at, delegated_by, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $a->id, $a->tenantId, $a->personId, $a->role, $a->scopeType->value, $a->scopeId, $a->establishmentId,
                $a->startsAt, $a->endsAt, $a->delegatedBy, $a->createdBy, $a->createdAt,
            ],
        );
    }

    public function find(string $tenantId, string $id): ?RoleAssignment
    {
        $row = $this->one('SELECT * FROM role_assignments WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $row === null ? null : self::map($row);
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->run('DELETE FROM role_assignments WHERE tenant_id = ? AND id = ?', [$tenantId, $id]) === 1;
    }

    /** @return list<RoleAssignment> affectations de la personne en vigueur à la date donnée */
    public function activeForPerson(string $tenantId, string $personId, string $now): array
    {
        return array_map(
            self::map(...),
            $this->rows(
                'SELECT * FROM role_assignments WHERE tenant_id = ? AND person_id = ? AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY id',
                [$tenantId, $personId, $now, $now],
            ),
        );
    }

    /**
     * @param array{person_id?: string, role?: string, scope_type?: string} $filters
     * @param string|null $onlyEstablishment limite aux affectations rattachées à cet établissement
     * @return list<RoleAssignment>
     */
    public function search(string $tenantId, array $filters, ?string $onlyEstablishment, ?string $afterId, int $limit): array
    {
        $where = ['tenant_id = ?'];
        $params = [$tenantId];
        foreach (['person_id', 'role', 'scope_type'] as $column) {
            if (isset($filters[$column])) {
                $where[] = $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        if ($onlyEstablishment !== null) {
            $where[] = 'establishment_id = ?';
            $params[] = $onlyEstablishment;
        }
        if ($afterId !== null) {
            $where[] = 'id > ?';
            $params[] = $afterId;
        }

        return array_map(
            self::map(...),
            $this->rows(
                sprintf('SELECT * FROM role_assignments WHERE %s ORDER BY id LIMIT %d', implode(' AND ', $where), max(1, $limit)),
                $params,
            ),
        );
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): RoleAssignment
    {
        return new RoleAssignment(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['person_id'],
            (string) $row['role'],
            ScopeType::from((string) $row['scope_type']),
            self::nullableString($row['scope_id']),
            self::nullableString($row['establishment_id']),
            (string) $row['starts_at'],
            self::nullableString($row['ends_at']),
            self::nullableString($row['delegated_by']),
            self::nullableString($row['created_by']),
            (string) $row['created_at'],
        );
    }
}
