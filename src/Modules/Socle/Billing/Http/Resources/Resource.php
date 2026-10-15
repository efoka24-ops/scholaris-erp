<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Http\Resources;

/** Sortie d'une ressource à liste blanche : jamais tenant_id ni colonne interne. */
final class Resource
{
    /**
     * @param list<string> $fields champs exposés, dans l'ordre
     * @param list<string> $jsonFields champs stockés en JSON, restitués décodés
     */
    private function __construct(
        private readonly array $fields,
        private readonly array $jsonFields = [],
    ) {
    }

    /**

     * @param array<string, mixed> $row

     * @return array<string, mixed>

     */
    public function toArray(array $row): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, $this->jsonFields, true) && is_string($value)) {
                $value = json_decode($value, true);
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**

     * @param list<array<string, mixed>> $rows

     * @return list<array<string, mixed>>

     */
    public function collection(array $rows): array
    {
        return array_map($this->toArray(...), $rows);
    }

    /**
     * Sous-liste d'une valeur de type inconnu (lignes imbriquées d'un agrégat) : les éléments qui ne sont pas des lignes sont ignorés.
     *
     * @return list<array<string, mixed>>
     */
    public function nested(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = $this->toArray($row);
            }
        }

        return $out;
    }

    public static function feeItem(): self
    {
        return new self(['id', 'establishment_id', 'kind', 'name', 'amount_xaf', 'active', 'created_at', 'updated_at']);
    }

    /**
     * Frais exposé : le code stable (S10) n'apparaît que s'il est renseigné (réponse inchangée sinon).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function feeItemRow(array $row): array
    {
        $out = self::feeItem()->toArray($row);

        return ($row['code'] ?? null) !== null ? $out + ['code' => (string) $row['code']] : $out;
    }

    public static function feeSchedule(): self
    {
        return new self(['id', 'establishment_id', 'academic_year_id', 'structure_id', 'level', 'track', 'fee_item_id', 'amount_xaf', 'terms', 'created_at', 'updated_at'], ['terms']);
    }

    public static function invoiceSummary(): self
    {
        return new self(['id', 'establishment_id', 'enrollment_id', 'learner_id', 'academic_year_id', 'structure_id', 'status', 'gross_xaf', 'discount_xaf', 'net_xaf', 'paid_xaf', 'balance_xaf', 'created_at']);
    }

    /**

     * @param array<string, mixed> $invoice

     * @return array<string, mixed>

     */
    public static function invoiceDetail(array $invoice): array
    {
        $line = new self(['id', 'fee_item_id', 'kind', 'label', 'gross_xaf', 'discount_xaf', 'net_xaf']);
        $installment = new self(['id', 'line_id', 'kind', 'seq_no', 'label', 'due_on', 'amount_xaf', 'paid_xaf', 'status', 'overdue']);
        $discount = new self(['id', 'kind', 'mode', 'rate_value', 'applies_to', 'amount_xaf', 'status', 'reason', 'requested_by', 'approved_by', 'created_at']);

        $lines = $line->nested($invoice['lines'] ?? null);
        foreach (is_array($invoice['lines'] ?? null) ? array_values($invoice['lines']) : [] as $index => $raw) {
            if (is_array($raw) && ($raw['fee_code'] ?? null) !== null && isset($lines[$index])) {
                $lines[$index]['fee_code'] = (string) $raw['fee_code'];
            }
        }

        return self::invoiceSummary()->toArray($invoice) + self::payerOf($invoice) + [
            'lines' => $lines,
            'installments' => $installment->nested($invoice['installments'] ?? null),
            'discounts' => $discount->nested($invoice['discounts'] ?? null),
        ];
    }

    /**
     * Nature et payeur tiers (S13) : présents seulement pour une facture libre ou établie au nom d'un tiers.
     *
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    public static function payerOf(array $invoice): array
    {
        $out = [];
        if (($invoice['invoice_kind'] ?? null) !== null) {
            $out['invoice_kind'] = (string) $invoice['invoice_kind'];
        }
        if (($invoice['payer_name'] ?? null) !== null) {
            $out['payer'] = ['kind' => $invoice['payer_kind'] ?? null, 'name' => $invoice['payer_name'], 'reference' => $invoice['payer_ref'] ?? null];
        }

        return $out;
    }

    public static function receipt(): self
    {
        return new self(['id', 'establishment_id', 'payment_id', 'year', 'number', 'amount_xaf', 'status', 'issued_at', 'sms_sent_at']);
    }

    /** Paiement tel qu'affiché au journal : statut brut et statut d'affichage (Rapproché, En caisse, Sans référence, Annulation · 2nd agent requis). */
    public static function payment(): self
    {
        return new self(['id', 'establishment_id', 'channel', 'amount_xaf', 'credit_xaf', 'status', 'journal_status', 'journal_amount_xaf', 'receipt_number', 'external_reference', 'payer_reference_id', 'learner_id', 'invoice_id', 'cash_session_id', 'received_by', 'received_at', 'reconciled_at']);
    }

    public static function allocation(): self
    {
        return new self(['installment_id', 'learner_id', 'establishment_id', 'amount_xaf', 'rule_version', 'created_at']);
    }

    public static function cashSession(): self
    {
        return new self(['id', 'establishment_id', 'register_no', 'cashier_id', 'opened_at', 'float_xaf', 'status', 'closed_at', 'expected_xaf', 'counted_xaf', 'variance_xaf', 'variance_user_id', 'note', 'cash_collected_xaf', 'receipts_count', 'expected_now_xaf', 'deposited_xaf']);
    }

    public static function reversal(): self
    {
        return new self(['id', 'establishment_id', 'payment_id', 'amount_xaf', 'reason', 'status', 'requested_by', 'approved_by', 'created_at', 'decided_at']);
    }

    public static function reconciliation(): self
    {
        return new self(['id', 'establishment_id', 'channel', 'source', 'imported_by', 'imported_at', 'lines_total', 'matched', 'unmatched']);
    }

    public static function paymentReference(): self
    {
        return new self(['id', 'guarantor_id', 'reference', 'created_at']);
    }
    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function cashDetail(array $session): array
    {
        $deposit = new self(['id', 'amount_xaf', 'reference', 'deposited_by', 'deposited_at']);

        return self::cashSession()->toArray($session) + ['deposits' => $deposit->nested($session['deposits'] ?? null)];
    }

    /**

     * @param array<string, mixed> $reconciliation

     * @return array<string, mixed>

     */
    public static function reconciliationDetail(array $reconciliation): array
    {
        $line = new self(['id', 'line_date', 'operator_reference', 'amount_xaf', 'phone', 'family_reference', 'status', 'payment_id']);

        return self::reconciliation()->toArray($reconciliation) + ['lines' => $line->nested($reconciliation['lines'] ?? null)];
    }
}
