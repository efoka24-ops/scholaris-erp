<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Rapprochements mobile money, relances et événements de webhook. */
final class ReconciliationRepository
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
        return $this->db->one('SELECT * FROM reconciliations WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function lines(string $tenantId, string $reconciliationId): array
    {
        return $this->db->all('SELECT * FROM reconciliation_lines WHERE tenant_id = ? AND reconciliation_id = ? ORDER BY id', [$tenantId, $reconciliationId]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: string|null} */
    public function page(string $tenantId, string $establishmentId, ListQuery $query): array
    {
        return $this->db->page('reconciliations', 'tenant_id = ? AND establishment_id = ?', [$tenantId, $establishmentId], $query);
    }

    public function updateCounts(string $tenantId, string $id, int $matched, int $unmatched): void
    {
        $this->db->update('reconciliations', ['matched' => $matched, 'unmatched' => $unmatched], ['tenant_id' => $tenantId, 'id' => $id]);
    }

    public function reminderExists(string $tenantId, string $installmentId, int $offset): bool
    {
        return $this->db->one(
            'SELECT id FROM reminders WHERE tenant_id = ? AND installment_id = ? AND offset_days = ?',
            [$tenantId, $installmentId, $offset],
        ) !== null;
    }

    /** @return array<string, mixed>|null */
    public function webhookEvent(string $provider, string $eventId): ?array
    {
        return $this->db->one('SELECT * FROM webhook_events WHERE provider = ? AND event_id = ?', [$provider, $eventId]);
    }

    public function finishWebhookEvent(string $id, string $outcome, ?string $tenantId, ?string $paymentId): void
    {
        $this->db->exec('UPDATE webhook_events SET outcome = ?, tenant_id = ?, payment_id = ? WHERE id = ?', [$outcome, $tenantId, $paymentId, $id]);
    }
}
