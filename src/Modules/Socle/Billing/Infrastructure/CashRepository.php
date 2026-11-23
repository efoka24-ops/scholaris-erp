<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Sessions de caisse et versements. */
final class CashRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($table, $row);
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $establishmentId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM cash_sessions WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function openFor(string $tenantId, string $establishmentId, string $cashierId): ?array
    {
        return $this->db->one(
            "SELECT * FROM cash_sessions WHERE tenant_id = ? AND establishment_id = ? AND cashier_id = ? AND status = 'open'",
            [$tenantId, $establishmentId, $cashierId],
        );
    }

    /** @param array<string, scalar|null> $set */
    public function update(string $tenantId, string $id, array $set): void
    {
        $this->db->update('cash_sessions', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function page(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page('cash_sessions', $where, $params, $query);
    }

    /** @return array{collected: int, receipts: int} espèces encaissées (paiements confirmés) et nombre de reçus de la session */
    public function totals(string $tenantId, string $sessionId): array
    {
        $row = $this->db->one(
            "SELECT COALESCE(SUM(amount_xaf), 0) AS total, COUNT(*) AS n FROM payments WHERE tenant_id = ? AND cash_session_id = ? AND status = 'confirmed'",
            [$tenantId, $sessionId],
        );

        return ['collected' => (int) ($row['total'] ?? 0), 'receipts' => (int) ($row['n'] ?? 0)];
    }

    public function deposited(string $tenantId, string $sessionId): int
    {
        return $this->db->int('SELECT COALESCE(SUM(amount_xaf), 0) FROM cash_deposits WHERE tenant_id = ? AND session_id = ?', [$tenantId, $sessionId]);
    }

    public function nextRegisterNumber(string $tenantId, string $establishmentId): int
    {
        return $this->db->int("SELECT COALESCE(MAX(register_no), 0) FROM cash_sessions WHERE tenant_id = ? AND establishment_id = ? AND status = 'open'", [$tenantId, $establishmentId]) + 1;
    }

    /** @return list<array<string, mixed>> */
    public function deposits(string $tenantId, string $sessionId): array
    {
        return $this->db->all('SELECT * FROM cash_deposits WHERE tenant_id = ? AND session_id = ? ORDER BY id', [$tenantId, $sessionId]);
    }
}
