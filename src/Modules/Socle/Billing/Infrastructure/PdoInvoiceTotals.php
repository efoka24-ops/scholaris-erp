<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;

final class PdoInvoiceTotals implements InvoiceTotals
{
    private const CHUNK = 400;

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function totals(string $tenantId, string $establishmentId, array $invoiceIds, string $today): array
    {
        $pdo = $this->connections->pdo();
        $totals = [];
        foreach (array_chunk(array_values(array_unique($invoiceIds)), self::CHUNK) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $statement = $pdo->prepare('SELECT id, net_xaf, paid_xaf, balance_xaf FROM invoices WHERE tenant_id = ? AND establishment_id = ? AND id IN (' . $marks . ')');
            $statement->execute([$tenantId, $establishmentId, ...$chunk]);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $totals[(string) $row['id']] = ['net_xaf' => (int) $row['net_xaf'], 'paid_xaf' => (int) $row['paid_xaf'], 'balance_xaf' => (int) $row['balance_xaf'], 'overdue_xaf' => 0];
            }
            $statement = $pdo->prepare(
                'SELECT invoice_id, SUM(amount_xaf - paid_xaf) AS overdue FROM installments WHERE tenant_id = ? AND establishment_id = ? AND due_on < ? '
                . 'AND amount_xaf > paid_xaf AND invoice_id IN (' . $marks . ') GROUP BY invoice_id',
            );
            $statement->execute([$tenantId, $establishmentId, $today, ...$chunk]);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (isset($totals[(string) $row['invoice_id']])) {
                    $totals[(string) $row['invoice_id']]['overdue_xaf'] = (int) $row['overdue'];
                }
            }
        }

        return $totals;
    }
}
