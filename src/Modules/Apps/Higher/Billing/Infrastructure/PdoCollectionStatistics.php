<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Infrastructure;

use Scholaris\Modules\Apps\Higher\Billing\Contracts\CollectionStatistics;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;
use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;

/**
 * Comptes de scolarité `hi_student_accounts` (filtrés par périmètre en SQL) puis totaux de leurs factures lus par lot via
 * InvoiceTotals du socle (jamais une requête par étudiant) et agrégés par école et filière.
 */
final class PdoCollectionStatistics implements CollectionStatistics
{
    public function __construct(
        private readonly AggregateReader $reader,
        private readonly InvoiceTotals $invoices,
    ) {
    }

    public function bySchoolProgram(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $today): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, ['school' => 'school_id', 'program' => 'program_id']);
        $where = 'tenant_id = ? AND establishment_id = ? AND ' . $scope;
        $params = [$tenantId, $establishmentId, ...$scopeParams];
        if ($filter->programId !== null) {
            $where .= ' AND program_id = ?';
            $params[] = $filter->programId;
        }
        if ($filter->academicYearId !== null) {
            $where .= ' AND academic_year_id = ?';
            $params[] = $filter->academicYearId;
        }
        $accounts = $this->reader->fetch('SELECT school_id, program_id, invoice_id FROM hi_student_accounts WHERE ' . $where, $params);
        $totals = $this->invoices->totals($tenantId, $establishmentId, array_map(static fn (array $a): string => (string) $a['invoice_id'], $accounts), $today);

        $groups = [];
        foreach ($accounts as $account) {
            $invoice = $totals[(string) $account['invoice_id']] ?? null;
            if ($invoice === null) {
                continue;
            }
            $program = $account['program_id'] === null ? null : (string) $account['program_id'];
            $key = $account['school_id'] . '|' . ($program ?? '');
            $groups[$key] ??= [
                'school_id' => (string) $account['school_id'], 'program_id' => $program, 'accounts' => 0, 'expected_xaf' => 0, 'collected_xaf' => 0,
                'outstanding_xaf' => 0, 'overdue_xaf' => 0, 'overdue_accounts' => 0,
            ];
            ++$groups[$key]['accounts'];
            $groups[$key]['expected_xaf'] += $invoice['net_xaf'];
            $groups[$key]['collected_xaf'] += $invoice['paid_xaf'];
            $groups[$key]['outstanding_xaf'] += $invoice['balance_xaf'];
            $groups[$key]['overdue_xaf'] += $invoice['overdue_xaf'];
            $groups[$key]['overdue_accounts'] += $invoice['overdue_xaf'] > 0 ? 1 : 0;
        }
        ksort($groups);

        return array_values($groups);
    }

    public function byChannel(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array
    {
        [$scope, $scopeParams] = $this->reader->scope($perimeter, ['school' => 'a.school_id', 'program' => 'a.program_id']);
        $where = "l.tenant_id = ? AND l.establishment_id = ? AND l.status = 'confirmed' AND " . $scope;
        $params = [$tenantId, $establishmentId, ...$scopeParams];
        if ($filter->programId !== null) {
            $where .= ' AND a.program_id = ?';
            $params[] = $filter->programId;
        }
        if ($filter->academicYearId !== null) {
            $where .= ' AND a.academic_year_id = ?';
            $params[] = $filter->academicYearId;
        }
        if ($filter->from !== null) {
            $where .= ' AND l.recorded_at >= ?';
            $params[] = $filter->from;
        }
        if ($filter->to !== null) {
            $where .= ' AND l.recorded_at < ?';
            $params[] = $filter->to . 'T99';
        }
        $rows = $this->reader->fetch(
            'SELECT l.channel, COUNT(*) AS payments, SUM(l.amount_xaf) AS amount_xaf FROM hi_payment_log l '
            . 'JOIN hi_student_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id WHERE ' . $where . ' GROUP BY l.channel ORDER BY l.channel',
            $params,
        );

        return array_map(static fn (array $r): array => ['channel' => (string) $r['channel'], 'payments' => (int) $r['payments'], 'amount_xaf' => (int) $r['amount_xaf']], $rows);
    }
}
