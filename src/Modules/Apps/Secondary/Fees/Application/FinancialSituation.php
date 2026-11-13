<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceTotals;
use Scholaris\Modules\Socle\Billing\Contracts\LearnerInvoices;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Situation financière par classe, par niveau et pour l'établissement, avec les prévisions d'encaissement (EX-FIN-07), en lecture
 * seulement sur le socle Billing : pour chaque classe de l'année (Setup), ses élèves inscrits, le total facturé, encaissé, restant dû
 * et échu (tranches passées non soldées), le taux de recouvrement et le nombre d'élèves débiteurs ; les prévisions sont les montants
 * des tranches à venir non soldées, par mois d'échéance. Aucune donnée nominative : seulement des agrégats.
 */
final class FinancialSituation
{
    private const ZERO = ['learners' => 0, 'debtors' => 0, 'invoiced_xaf' => 0, 'paid_xaf' => 0, 'balance_xaf' => 0, 'overdue_xaf' => 0];

    public function __construct(
        private readonly ClassProfiles $classes,
        private readonly EnrollmentDirectory $enrollments,
        private readonly LearnerInvoices $learnerInvoices,
        private readonly InvoiceTotals $totals,
        private readonly InvoiceIssuer $invoices,
        private readonly CurrentPeriod $periods,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $establishmentId, ?string $academicYearId): array
    {
        $today = $this->clock->today();
        $academicYearId ??= $this->periods->at($tenantId, $establishmentId, $today)?->academicYearId;
        if ($academicYearId === null) {
            throw HttpException::validation(['academic_year_id' => ['Aucune période en cours : précisez l\'année scolaire.']]);
        }
        $classes = [];
        $levels = [];
        $establishment = self::ZERO;
        $forecast = [];
        $seen = [];
        foreach ($this->classes->forYear($tenantId, $establishmentId, $academicYearId) as $class) {
            $row = self::ZERO;
            foreach (array_values(array_unique($this->enrollments->personIdsInStructure($tenantId, $class->structureId))) as $learnerId) {
                if (isset($seen[$learnerId])) {
                    continue;
                }
                $seen[$learnerId] = true;
                ++$row['learners'];
                $ids = $this->learnerInvoices->idsFor($tenantId, $establishmentId, $learnerId);
                if ($ids === []) {
                    continue;
                }
                $balance = 0;
                foreach ($this->totals->totals($tenantId, $establishmentId, $ids, $today) as $total) {
                    $row['invoiced_xaf'] += $total['net_xaf'];
                    $row['paid_xaf'] += $total['paid_xaf'];
                    $row['overdue_xaf'] += $total['overdue_xaf'];
                    $balance += $total['balance_xaf'];
                }
                $row['balance_xaf'] += $balance;
                $balance > 0 && ++$row['debtors'];
                foreach ($ids as $invoiceId) {
                    foreach ($this->invoices->find($tenantId, $establishmentId, $invoiceId)->installments ?? [] as $installment) {
                        $left = $installment['amount_xaf'] - $installment['paid_xaf'];
                        if ($left > 0 && $installment['due_on'] >= $today) {
                            $month = substr($installment['due_on'], 0, 7);
                            $forecast[$month] = ($forecast[$month] ?? 0) + $left;
                        }
                    }
                }
            }
            $classes[] = ['class_id' => $class->structureId, 'label' => $class->label, 'level_code' => $class->levelCode] + self::withRate($row);
            $levels[$class->levelCode] = self::add($levels[$class->levelCode] ?? self::ZERO, $row);
            $establishment = self::add($establishment, $row);
        }
        ksort($forecast);
        $levelRows = [];
        foreach ($levels as $code => $totals) {
            $levelRows[] = ['level_code' => (string) $code] + self::withRate($totals);
        }

        return [
            'academic_year_id' => $academicYearId, 'as_of' => $today, 'classes' => $classes, 'levels' => $levelRows,
            'establishment' => self::withRate($establishment),
            'forecast' => array_map(static fn (string $month, int $amount): array => ['month' => $month, 'expected_xaf' => $amount], array_keys($forecast), array_values($forecast)),
        ];
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = ($a[$key] ?? 0) + $value;
        }

        return $a;
    }

    /**
     * @param array<string, int> $row
     * @return array<string, int|float|null>
     */
    private static function withRate(array $row): array
    {
        return $row + ['recovery_rate' => $row['invoiced_xaf'] > 0 ? round(100 * $row['paid_xaf'] / $row['invoiced_xaf'], 1) : null];
    }
}
