<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;
use Scholaris\Modules\Socle\Billing\Infrastructure\InvoiceRepository;
use Scholaris\Modules\Socle\Organisation\Contracts\BalanceCarryOver;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;

/**
 * Report des soldes à la clôture d'une année : chaque facture encore ouverte de l'année close est soldée (ses tranches
 * impayées sont ramenées au montant déjà payé, comme pour une remise, et la facture passe à « carried ») et son reste dû
 * devient une facture « Report de solde » de l'année suivante, payable et affectable comme toute autre. Idempotent : une
 * facture source n'est reportée qu'une fois (index unique de balance_carry_overs), rejouer ne reporte rien de plus.
 */
final class YearBalanceCarryOver implements BalanceCarryOver
{
    public const SYSTEM_ACTOR = 'system';
    public const FEE_NAME = 'Report de solde';

    public function __construct(
        private readonly Db $db,
        private readonly InvoiceRepository $invoices,
        private readonly AuditLog $audit,
        private readonly BillingClock $clock,
    ) {
    }

    public function carryOver(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        $open = $this->db->all(
            "SELECT * FROM invoices WHERE tenant_id = ? AND establishment_id = ? AND academic_year_id = ? AND status = 'open' AND balance_xaf > 0 ORDER BY id",
            [$tenantId, $establishmentId, $fromYearId],
        );
        $invoices = 0;
        $total = 0;
        foreach ($open as $invoice) {
            $amount = $this->db->transaction(fn (): int => $this->carryOne($tenantId, $establishmentId, $fromYearId, $toYearId, $invoice));
            if ($amount > 0) {
                ++$invoices;
                $total += $amount;
            }
        }

        return new ClosureStepResult(
            ClosureStepResult::DONE,
            $invoices === 0 ? 'Aucun solde impayé à reporter.' : sprintf('%d solde(s) impayé(s) reporté(s) sur l\'année suivante, soit %d XAF.', $invoices, $total),
            ['invoices_carried' => $invoices, 'amount_xaf' => $total],
        );
    }

    /**
     * @param array<string, mixed> $source
     * @return int montant reporté (0 si déjà reporté ou rien à reporter)
     */
    private function carryOne(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId, array $source): int
    {
        $sourceId = (string) $source['id'];
        if ($this->db->one('SELECT id FROM balance_carry_overs WHERE tenant_id = ? AND source_invoice_id = ?', [$tenantId, $sourceId]) !== null) {
            return 0;
        }
        $remaining = 0;
        foreach ($this->invoices->installments($tenantId, $sourceId) as $installment) {
            $left = (int) $installment['amount_xaf'] - (int) $installment['paid_xaf'];
            if ($left > 0) {
                $remaining += $left;
                $this->invoices->reduceInstallment($tenantId, (string) $installment['id'], $left);
            }
        }
        if ($remaining <= 0) {
            return 0;
        }

        $now = $this->clock->now();
        $learnerId = (string) $source['learner_id'];
        $feeItemId = $this->feeItem($tenantId, $establishmentId, $now);
        $newId = (string) new Ulid();
        $lineId = (string) new Ulid();
        $this->invoices->insert('invoices', [
            'id' => $newId, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'enrollment_id' => 'carry:' . $sourceId,
            'learner_id' => $learnerId, 'academic_year_id' => $toYearId, 'structure_id' => null, 'status' => 'open',
            'gross_xaf' => $remaining, 'discount_xaf' => 0, 'net_xaf' => $remaining, 'paid_xaf' => 0, 'balance_xaf' => $remaining,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->invoices->insert('invoice_lines', [
            'id' => $lineId, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'invoice_id' => $newId,
            'fee_item_id' => $feeItemId, 'kind' => 'other', 'label' => self::FEE_NAME,
            'gross_xaf' => $remaining, 'discount_xaf' => 0, 'net_xaf' => $remaining,
        ]);
        $this->invoices->insert('installments', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'invoice_id' => $newId,
            'line_id' => $lineId, 'learner_id' => $learnerId, 'kind' => 'other', 'seq_no' => 1, 'label' => self::FEE_NAME,
            'due_on' => $this->clock->today(), 'amount_xaf' => $remaining, 'paid_xaf' => 0,
        ]);
        $this->db->update('invoices', ['status' => 'carried', 'balance_xaf' => 0, 'updated_at' => $now], ['tenant_id' => $tenantId, 'id' => $sourceId]);
        $this->db->insert('balance_carry_overs', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'learner_id' => $learnerId,
            'from_year_id' => $fromYearId, 'to_year_id' => $toYearId, 'source_invoice_id' => $sourceId, 'new_invoice_id' => $newId,
            'amount_xaf' => $remaining, 'created_at' => $now,
        ]);
        $this->audit->append($tenantId, self::SYSTEM_ACTOR, 'invoice.balance_carried_over', 'invoice', $sourceId, ['status' => 'open', 'balance_xaf' => $remaining], [
            'status' => 'carried', 'new_invoice_id' => $newId, 'to_year_id' => $toYearId, 'amount_xaf' => $remaining,
        ]);

        return $remaining;
    }

    /** Article de catalogue « Report de solde » (inactif : il n'apparaît pas dans les grilles), créé au premier report. */
    private function feeItem(string $tenantId, string $establishmentId, string $now): string
    {
        $row = $this->db->one(
            'SELECT id FROM fee_items WHERE tenant_id = ? AND establishment_id = ? AND kind = ? AND name = ?',
            [$tenantId, $establishmentId, 'other', self::FEE_NAME],
        );
        if ($row !== null) {
            return (string) $row['id'];
        }
        $id = (string) new Ulid();
        $this->db->insert('fee_items', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'kind' => 'other', 'name' => self::FEE_NAME,
            'amount_xaf' => 0, 'active' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }
}
