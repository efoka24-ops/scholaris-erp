<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Billing\Infrastructure\Db;

/** Export comptable CSV d'un établissement : journal des encaissements, ventes (lignes de facture) et remises. */
final class AccountingExport
{
    public const KINDS = ['journal', 'sales', 'discounts'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return string CSV (UTF-8, séparateur virgule) */
    public function export(string $tenantId, string $establishmentId, string $kind, string $from, string $to): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw HttpException::notFound('Export inconnu.');
        }
        $start = $from;
        $end = $to . '~';

        return match ($kind) {
            'journal' => $this->journal($tenantId, $establishmentId, $start, $end),
            'sales' => $this->sales($tenantId, $establishmentId, $start, $end),
            default => $this->discounts($tenantId, $establishmentId, $start, $end),
        };
    }

    private function journal(string $tenantId, string $establishmentId, string $start, string $end): string
    {
        $rows = [['date', 'receipt', 'channel', 'external_reference', 'family_reference', 'amount_xaf', 'status', 'received_by']];
        $payments = $this->db->all(
            "SELECT p.*, r.number AS receipt_number, f.reference AS family_reference FROM payments p
             LEFT JOIN receipts r ON r.payment_id = p.id AND r.tenant_id = p.tenant_id
             LEFT JOIN payment_references f ON f.id = p.payer_reference_id AND f.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.establishment_id = ? AND p.status IN ('confirmed', 'reversed') AND p.received_at >= ? AND p.received_at < ? ORDER BY p.received_at, p.id",
            [$tenantId, $establishmentId, $start, $end],
        );
        foreach ($payments as $p) {
            $rows[] = [$p['received_at'], $p['receipt_number'], $p['channel'], $p['external_reference'], $p['family_reference'], (int) $p['amount_xaf'], 'payment', $p['received_by']];
        }
        $reversals = $this->db->all(
            "SELECT v.*, r.number AS receipt_number FROM reversals v LEFT JOIN receipts r ON r.payment_id = v.payment_id AND r.tenant_id = v.tenant_id
             WHERE v.tenant_id = ? AND v.establishment_id = ? AND v.status = 'approved' AND v.decided_at >= ? AND v.decided_at < ? ORDER BY v.decided_at, v.id",
            [$tenantId, $establishmentId, $start, $end],
        );
        foreach ($reversals as $v) {
            $rows[] = [$v['decided_at'], $v['receipt_number'], null, null, null, (int) $v['amount_xaf'], 'reversal', $v['approved_by']];
        }

        return $this->csv($rows);
    }

    private function sales(string $tenantId, string $establishmentId, string $start, string $end): string
    {
        $rows = [['date', 'invoice', 'learner', 'kind', 'label', 'gross_xaf', 'discount_xaf', 'net_xaf']];
        $lines = $this->db->all(
            'SELECT l.*, i.created_at AS invoice_date, i.learner_id FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id AND i.tenant_id = l.tenant_id
             WHERE l.tenant_id = ? AND l.establishment_id = ? AND i.created_at >= ? AND i.created_at < ? ORDER BY i.created_at, l.id',
            [$tenantId, $establishmentId, $start, $end],
        );
        foreach ($lines as $l) {
            $rows[] = [$l['invoice_date'], $l['invoice_id'], $l['learner_id'], $l['kind'], $l['label'], (int) $l['gross_xaf'], (int) $l['discount_xaf'], (int) $l['net_xaf']];
        }

        return $this->csv($rows);
    }

    private function discounts(string $tenantId, string $establishmentId, string $start, string $end): string
    {
        $rows = [['date', 'invoice', 'learner', 'kind', 'mode', 'value', 'amount_xaf', 'reason']];
        $discounts = $this->db->all(
            "SELECT * FROM discounts WHERE tenant_id = ? AND establishment_id = ? AND status = 'approved' AND created_at >= ? AND created_at < ? ORDER BY created_at, id",
            [$tenantId, $establishmentId, $start, $end],
        );
        foreach ($discounts as $d) {
            $rows[] = [$d['created_at'], $d['invoice_id'], $d['learner_id'], $d['kind'], $d['mode'], (int) $d['rate_value'], (int) $d['amount_xaf'], $d['reason']];
        }

        return $this->csv($rows);
    }

    /** @param list<list<mixed>> $rows */
    private function csv(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map($this->cell(...), $row));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        $text = (string) $value;
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }
}
