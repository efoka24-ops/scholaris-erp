<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use PDO;
use Scholaris\Core\Database\ConnectionFactory;

/** Accès PDO aux tables de congés. Toute requête porte `tenant_id` (et `establishment_id` pour les demandes). */
final class LeaveRepository
{
    private const REQUEST_COLUMNS = [
        'id', 'tenant_id', 'establishment_id', 'staff_id', 'leave_type', 'starts_on', 'ends_on', 'days', 'reason', 'status',
        'approval_id', 'requested_by', 'decided_by', 'decided_at', 'created_at', 'updated_at',
    ];

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    private function pdo(): PDO
    {
        return $this->connections->pdo();
    }

    /** @param array<string, scalar|null> $row */
    public function insertRequest(array $row): void
    {
        $this->pdo()->prepare(sprintf(
            'INSERT INTO leave_requests (%s) VALUES (%s)',
            implode(', ', self::REQUEST_COLUMNS),
            implode(', ', array_fill(0, count(self::REQUEST_COLUMNS), '?')),
        ))->execute(array_map(static fn (string $column) => $row[$column] ?? null, self::REQUEST_COLUMNS));
    }

    /** @return array<string, mixed>|null */
    public function findRequest(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->rows(
            'SELECT * FROM leave_requests WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
            [$tenantId, $establishmentId, $id],
        )[0] ?? null;
    }

    public function updateStatus(string $tenantId, string $establishmentId, string $id, string $status, ?string $decidedBy, ?string $decidedAt, string $now): void
    {
        $this->pdo()->prepare(
            'UPDATE leave_requests SET status = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?',
        )->execute([$status, $decidedBy, $decidedAt, $now, $tenantId, $establishmentId, $id]);
    }

    public function attachApproval(string $tenantId, string $establishmentId, string $id, string $approvalId): void
    {
        $this->pdo()->prepare('UPDATE leave_requests SET approval_id = ? WHERE tenant_id = ? AND establishment_id = ? AND id = ?')
            ->execute([$approvalId, $tenantId, $establishmentId, $id]);
    }

    /**
     * @param list<scalar> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findBalance(string $tenantId, string $staffId, string $type, int $year): ?array
    {
        return $this->rows(
            'SELECT * FROM leave_balances WHERE tenant_id = ? AND staff_id = ? AND leave_type = ? AND leave_year = ?',
            [$tenantId, $staffId, $type, $year],
        )[0] ?? null;
    }

    public function upsertBalance(string $id, string $tenantId, string $establishmentId, string $staffId, string $type, int $year, int $entitled, string $now): void
    {
        if ($this->findBalance($tenantId, $staffId, $type, $year) === null) {
            $this->pdo()->prepare(
                'INSERT INTO leave_balances (id, tenant_id, establishment_id, staff_id, leave_type, leave_year, entitled_days, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([$id, $tenantId, $establishmentId, $staffId, $type, $year, $entitled, $now]);

            return;
        }
        $this->pdo()->prepare(
            'UPDATE leave_balances SET entitled_days = ?, updated_at = ? WHERE tenant_id = ? AND staff_id = ? AND leave_type = ? AND leave_year = ?',
        )->execute([$entitled, $now, $tenantId, $staffId, $type, $year]);
    }
}
