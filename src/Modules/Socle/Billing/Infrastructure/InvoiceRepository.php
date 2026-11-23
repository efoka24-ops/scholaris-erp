<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Http\ListQuery;
use Scholaris\Modules\Socle\Billing\Domain\Due;

/** Factures, lignes, tranches et remises. Toutes les requêtes portent tenant_id. */
final class InvoiceRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM invoices WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return array<string, mixed>|null */
    public function findForEnrollment(string $tenantId, string $enrollmentId): ?array
    {
        return $this->db->one('SELECT * FROM invoices WHERE tenant_id = ? AND enrollment_id = ?', [$tenantId, $enrollmentId]);
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

        return $this->db->page('invoices', $where, $params, $query);
    }

    /** @param array<string, scalar|null> $row */
    public function insert(string $table, array $row): void
    {
        $this->db->insert($table, $row);
    }

    /** @return list<array<string, mixed>> */
    public function lines(string $tenantId, string $invoiceId): array
    {
        return $this->db->all('SELECT * FROM invoice_lines WHERE tenant_id = ? AND invoice_id = ? ORDER BY id', [$tenantId, $invoiceId]);
    }

    /** @return list<array<string, mixed>> */
    public function installments(string $tenantId, string $invoiceId): array
    {
        return $this->db->all('SELECT * FROM installments WHERE tenant_id = ? AND invoice_id = ? ORDER BY due_on, seq_no, id', [$tenantId, $invoiceId]);
    }

    /** @return list<array<string, mixed>> */
    public function discounts(string $tenantId, string $invoiceId): array
    {
        return $this->db->all('SELECT * FROM discounts WHERE tenant_id = ? AND invoice_id = ? ORDER BY id', [$tenantId, $invoiceId]);
    }

    /** @return array<string, mixed>|null */
    public function discount(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM discounts WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return list<array<string, mixed>> tranches d'une facture pour les lignes d'un type (ou toutes) encore partiellement dues */
    public function discountableInstallments(string $tenantId, string $invoiceId, string $appliesTo): array
    {
        $sql = 'SELECT i.*, l.gross_xaf AS line_gross FROM installments i JOIN invoice_lines l ON l.id = i.line_id AND l.tenant_id = i.tenant_id
                WHERE i.tenant_id = ? AND i.invoice_id = ?';
        $params = [$tenantId, $invoiceId];
        if ($appliesTo !== 'all') {
            $sql .= ' AND l.kind = ?';
            $params[] = $appliesTo;
        }

        return $this->db->all($sql . ' ORDER BY i.due_on, i.seq_no, i.id', $params);
    }

    /** @return array{base: int, discounted: int} brut des lignes visées par une cible de remise et remises déjà appliquées sur elles */
    public function discountBase(string $tenantId, string $invoiceId, string $appliesTo): array
    {
        $sql = 'SELECT COALESCE(SUM(gross_xaf), 0) AS base, COALESCE(SUM(discount_xaf), 0) AS discounted FROM invoice_lines WHERE tenant_id = ? AND invoice_id = ?';
        $params = [$tenantId, $invoiceId];
        if ($appliesTo !== 'all') {
            $sql .= ' AND kind = ?';
            $params[] = $appliesTo;
        }
        $row = $this->db->one($sql, $params);

        return ['base' => (int) ($row['base'] ?? 0), 'discounted' => (int) ($row['discounted'] ?? 0)];
    }

    /** @return array<string, int> part payée par ligne de facture (identifiant de ligne => XAF) */
    public function paidByLine(string $tenantId, string $invoiceId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT line_id, COALESCE(SUM(paid_xaf), 0) AS paid FROM installments WHERE tenant_id = ? AND invoice_id = ? GROUP BY line_id', [$tenantId, $invoiceId]) as $row) {
            $out[(string) $row['line_id']] = (int) $row['paid'];
        }

        return $out;
    }

    public function reduceInstallment(string $tenantId, string $installmentId, int $by): void
    {
        $this->db->exec('UPDATE installments SET amount_xaf = amount_xaf - ? WHERE tenant_id = ? AND id = ?', [$by, $tenantId, $installmentId]);
    }

    public function addToLineDiscount(string $tenantId, string $lineId, int $by): void
    {
        $this->db->exec(
            'UPDATE invoice_lines SET discount_xaf = discount_xaf + ?, net_xaf = net_xaf - ? WHERE tenant_id = ? AND id = ?',
            [$by, $by, $tenantId, $lineId],
        );
    }

    public function addPaid(string $tenantId, string $installmentId, int $delta): void
    {
        $this->db->exec('UPDATE installments SET paid_xaf = paid_xaf + ? WHERE tenant_id = ? AND id = ?', [$delta, $tenantId, $installmentId]);
    }

    /** @return array<string, mixed>|null */
    public function installment(string $tenantId, string $id): ?array
    {
        return $this->db->one('SELECT * FROM installments WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** Recalcule les totaux d'une facture à partir de ses lignes et tranches. */
    public function recompute(string $tenantId, string $invoiceId, string $now): void
    {
        $gross = $this->db->int('SELECT COALESCE(SUM(gross_xaf), 0) FROM invoice_lines WHERE tenant_id = ? AND invoice_id = ?', [$tenantId, $invoiceId]);
        $discount = $this->db->int('SELECT COALESCE(SUM(discount_xaf), 0) FROM invoice_lines WHERE tenant_id = ? AND invoice_id = ?', [$tenantId, $invoiceId]);
        $paid = $this->db->int('SELECT COALESCE(SUM(paid_xaf), 0) FROM installments WHERE tenant_id = ? AND invoice_id = ?', [$tenantId, $invoiceId]);
        $net = $gross - $discount;
        $balance = $net - $paid;

        // Statut calculé ici : sous SQLite, un paramètre lié comparé à 0 dans le SQL est un texte et ne vaut jamais « <= 0 ».
        $this->db->exec(
            "UPDATE invoices SET gross_xaf = ?, discount_xaf = ?, net_xaf = ?, paid_xaf = ?, balance_xaf = ?,
             status = CASE WHEN status IN ('cancelled', 'carried') THEN status ELSE ? END, updated_at = ?
             WHERE tenant_id = ? AND id = ?",
            [$gross, $discount, $net, $paid, $balance, $balance <= 0 ? 'paid' : 'open', $now, $tenantId, $invoiceId],
        );
    }

    /**
     * Sommes encore dues pour des apprenants (tous établissements du client), sur les factures ouvertes.
     *
     * @param list<string> $learnerIds
     * @return list<Due>
     */
    public function dues(string $tenantId, array $learnerIds, ?string $invoiceId = null): array
    {
        if ($learnerIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($learnerIds), '?'));
        $sql = "SELECT i.* FROM installments i JOIN invoices v ON v.id = i.invoice_id AND v.tenant_id = i.tenant_id
                WHERE i.tenant_id = ? AND v.status = 'open' AND i.paid_xaf < i.amount_xaf AND i.learner_id IN ($marks)";
        $params = [$tenantId, ...$learnerIds];
        if ($invoiceId !== null) {
            $sql .= ' AND i.invoice_id = ?';
            $params[] = $invoiceId;
        }

        return array_map(
            static fn (array $r): Due => new Due(
                (string) $r['id'],
                (string) $r['invoice_id'],
                (string) $r['learner_id'],
                (string) $r['establishment_id'],
                (string) $r['kind'],
                (string) $r['due_on'],
                (int) $r['amount_xaf'] - (int) $r['paid_xaf'],
                (int) $r['seq_no'],
            ),
            $this->db->all($sql, $params),
        );
    }

    /**
     * Tranches impayées d'apprenants, pour les soldes (tous établissements du client).
     *
     * @param list<string> $learnerIds
     * @return list<array<string, mixed>>
     */
    public function outstandingFor(string $tenantId, array $learnerIds): array
    {
        if ($learnerIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($learnerIds), '?'));

        return $this->db->all(
            "SELECT i.* FROM installments i JOIN invoices v ON v.id = i.invoice_id AND v.tenant_id = i.tenant_id
             WHERE i.tenant_id = ? AND v.status = 'open' AND i.paid_xaf < i.amount_xaf AND i.learner_id IN ($marks) ORDER BY i.due_on, i.seq_no",
            [$tenantId, ...$learnerIds],
        );
    }

    /** @return list<string> */
    public function learnersInArrears(string $tenantId, string $establishmentId, string $today): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['learner_id'],
            $this->db->all(
                'SELECT DISTINCT learner_id FROM installments WHERE tenant_id = ? AND establishment_id = ? AND due_on < ? AND paid_xaf < amount_xaf ORDER BY learner_id',
                [$tenantId, $establishmentId, $today],
            ),
        );
    }

    public function balance(string $tenantId, string $establishmentId, string $learnerId): int
    {
        return $this->db->int(
            'SELECT COALESCE(SUM(amount_xaf - paid_xaf), 0) FROM installments WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ?',
            [$tenantId, $establishmentId, $learnerId],
        );
    }

    /** @return array<string, int> solde restant dû par apprenant de l'établissement (une seule requête groupée) */
    public function balancesByLearner(string $tenantId, string $establishmentId): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT learner_id, COALESCE(SUM(amount_xaf - paid_xaf), 0) AS due FROM installments WHERE tenant_id = ? AND establishment_id = ? GROUP BY learner_id',
            [$tenantId, $establishmentId],
        ) as $row) {
            $out[(string) $row['learner_id']] = (int) $row['due'];
        }

        return $out;
    }

    /** @return array{amount: int, oldest: string|null} montant en retard et plus ancienne échéance en retard */
    public function overdue(string $tenantId, string $establishmentId, string $learnerId, string $today): array
    {
        $row = $this->db->one(
            'SELECT COALESCE(SUM(amount_xaf - paid_xaf), 0) AS due, MIN(due_on) AS oldest FROM installments
             WHERE tenant_id = ? AND establishment_id = ? AND learner_id = ? AND due_on < ? AND paid_xaf < amount_xaf',
            [$tenantId, $establishmentId, $learnerId, $today],
        );

        return ['amount' => (int) ($row['due'] ?? 0), 'oldest' => isset($row['oldest']) ? (string) $row['oldest'] : null];
    }

    /**
     * Tranches impayées dont l'échéance est dans [from, to], pour les relances.
     *
     * @return list<array<string, mixed>>
     */
    public function dueBetween(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        return $this->db->all(
            'SELECT * FROM installments WHERE tenant_id = ? AND establishment_id = ? AND due_on >= ? AND due_on <= ? AND paid_xaf < amount_xaf ORDER BY due_on, id',
            [$tenantId, $establishmentId, $from, $to],
        );
    }

    /** @return list<array{tenant_id: string, establishment_id: string}> */
    public function activeScopes(): array
    {
        /** @var list<array{tenant_id: string, establishment_id: string}> $rows */
        $rows = $this->db->all('SELECT DISTINCT tenant_id, establishment_id FROM installments WHERE paid_xaf < amount_xaf ORDER BY tenant_id, establishment_id');

        return $rows;
    }
}
