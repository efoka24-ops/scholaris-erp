<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Http\ListQuery;

/** Paiements, affectations, reçus, références famille, annulations et événements de webhook. Toutes les requêtes portent tenant_id. */
final class PaymentRepository
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
    public function payment(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM payments WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function paymentByKey(string $tenantId, string $idempotencyKey): ?array
    {
        return $this->db->one('SELECT * FROM payments WHERE tenant_id = ? AND idempotency_key = ?', [$tenantId, $idempotencyKey]);
    }

    /** @return array<string, mixed>|null */
    public function paymentByExternal(string $tenantId, string $externalReference): ?array
    {
        return $this->db->one('SELECT * FROM payments WHERE tenant_id = ? AND external_reference = ?', [$tenantId, $externalReference]);
    }

    /** @return list<array<string, mixed>> paiements en attente portant cette référence d'opérateur (tous clients : le webhook ne connaît pas le client) */
    public function pendingByExternal(string $externalReference): array
    {
        return $this->db->all("SELECT * FROM payments WHERE external_reference = ? AND status = 'pending'", [$externalReference]);
    }

    /** @param array<string, scalar|null> $set */
    public function updatePayment(string $tenantId, string $id, array $set): void
    {
        $this->db->update('payments', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function paymentPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            if ($column === 'day') {
                $where .= ' AND received_at >= ? AND received_at < ?';
                $params[] = $value;
                $params[] = $value . '~';
                continue;
            }
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page('payments', $where, $params, $query);
    }

    /** @return array<string, mixed>|null */
    public function receipt(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM receipts WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function receiptForPayment(string $tenantId, string $paymentId): ?array
    {
        return $this->db->one('SELECT * FROM receipts WHERE tenant_id = ? AND payment_id = ?', [$tenantId, $paymentId]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function receiptPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page('receipts', $where, $params, $query);
    }

    /**
     * Reçus des seuls paiements d'un payeur en libre-service : ceux de ses références famille ou de ses apprenants.
     *
     * @param list<string> $referenceIds
     * @param list<string> $learnerIds
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public function receiptPageOwnedBy(string $tenantId, string $establishmentId, array $referenceIds, array $learnerIds, array $filters, ListQuery $query): array
    {
        $owners = [];
        $ownerParams = [$tenantId];
        if ($referenceIds !== []) {
            $owners[] = 'p.payer_reference_id IN (' . implode(', ', array_fill(0, count($referenceIds), '?')) . ')';
            array_push($ownerParams, ...$referenceIds);
        }
        if ($learnerIds !== []) {
            $marks = implode(', ', array_fill(0, count($learnerIds), '?'));
            $owners[] = 'p.learner_id IN (' . $marks . ')';
            $owners[] = 'p.invoice_id IN (SELECT i.id FROM invoices i WHERE i.tenant_id = p.tenant_id AND i.learner_id IN (' . $marks . '))';
            array_push($ownerParams, ...$learnerIds, ...$learnerIds);
        }
        if ($owners === []) {
            return [[], null];
        }
        $where = 'tenant_id = ? AND establishment_id = ? AND payment_id IN (SELECT p.id FROM payments p WHERE p.tenant_id = ? AND (' . implode(' OR ', $owners) . '))';
        $params = [$tenantId, $establishmentId, ...$ownerParams];
        foreach ($filters as $column => $value) {
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page('receipts', $where, $params, $query);
    }

    public function markReceiptSms(string $tenantId, string $receiptId, string $at): void
    {
        $this->db->update('receipts', ['sms_sent_at' => $at], ['tenant_id' => $tenantId, 'id' => $receiptId]);
    }

    /** @return list<array<string, mixed>> */
    public function allocationsOf(string $tenantId, string $paymentId): array
    {
        return $this->db->all('SELECT * FROM allocations WHERE tenant_id = ? AND payment_id = ? ORDER BY id', [$tenantId, $paymentId]);
    }

    /** @return array<string, mixed>|null */
    public function referenceByValue(string $reference): ?array
    {
        return $this->db->one('SELECT * FROM payment_references WHERE reference = ?', [$reference]);
    }

    /** @return array<string, mixed>|null */
    public function referenceForTenant(string $tenantId, string $reference): ?array
    {
        return $this->db->one('SELECT * FROM payment_references WHERE tenant_id = ? AND reference = ?', [$tenantId, $reference]);
    }

    /** @return array<string, mixed>|null */
    public function referenceById(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM payment_references WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function referenceOfGuarantor(string $tenantId, string $guarantorId): ?array
    {
        return $this->db->one('SELECT * FROM payment_references WHERE tenant_id = ? AND guarantor_id = ?', [$tenantId, $guarantorId]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function referencePage(string $tenantId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ?';
        $params = [$tenantId];
        foreach ($filters as $column => $value) {
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page('payment_references', $where, $params, $query);
    }

    /** @return list<array<string, mixed>> paiements confirmés d'une référence famille ayant encore du crédit */
    public function creditedPayments(string $tenantId, string $referenceId): array
    {
        return $this->db->all(
            "SELECT * FROM payments WHERE tenant_id = ? AND payer_reference_id = ? AND status = 'confirmed' AND credit_xaf > 0 ORDER BY received_at, id",
            [$tenantId, $referenceId],
        );
    }

    public function creditOf(string $tenantId, string $referenceId): int
    {
        return $this->db->int(
            "SELECT COALESCE(SUM(credit_xaf), 0) FROM payments WHERE tenant_id = ? AND payer_reference_id = ? AND status = 'confirmed'",
            [$tenantId, $referenceId],
        );
    }

    /** @return array<string, mixed>|null */
    public function reversal(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM reversals WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function pendingReversalOf(string $tenantId, string $paymentId): ?array
    {
        return $this->db->one("SELECT * FROM reversals WHERE tenant_id = ? AND payment_id = ? AND status = 'pending'", [$tenantId, $paymentId]);
    }

    /** @param array<string, scalar|null> $set */
    public function updateReversal(string $tenantId, string $id, array $set): void
    {
        $this->db->update('reversals', $set, ['tenant_id' => $tenantId, 'id' => $id]);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    public function reversalPage(string $tenantId, string $establishmentId, array $filters, ListQuery $query): array
    {
        $where = 'tenant_id = ? AND establishment_id = ?';
        $params = [$tenantId, $establishmentId];
        foreach ($filters as $column => $value) {
            $where .= ' AND ' . $column . ' = ?';
            $params[] = $value;
        }

        return $this->db->page('reversals', $where, $params, $query);
    }
    /**
     * @param list<string> $paymentIds
     * @return array<string, string> numéro de reçu par identifiant de paiement
     */
    public function receiptNumbers(string $tenantId, array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($paymentIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT payment_id, number FROM receipts WHERE tenant_id = ? AND payment_id IN ($marks)", [$tenantId, ...$paymentIds]) as $row) {
            $out[(string) $row['payment_id']] = (string) $row['number'];
        }

        return $out;
    }

    /**
     * @param list<string> $paymentIds
     * @return list<string> paiements ayant une annulation en attente de second agent
     */
    public function paymentsWithPendingReversal(string $tenantId, array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($paymentIds), '?'));

        return array_map(
            static fn (array $row): string => (string) $row['payment_id'],
            $this->db->all("SELECT payment_id FROM reversals WHERE tenant_id = ? AND status = 'pending' AND payment_id IN ($marks)", [$tenantId, ...$paymentIds]),
        );
    }
}
