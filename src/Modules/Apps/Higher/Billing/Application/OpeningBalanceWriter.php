<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\OpeningBalances;
use Scholaris\Modules\Apps\Higher\Billing\Domain\HigherFees;
use Scholaris\Modules\Apps\Higher\Billing\Infrastructure\BillingRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;

/** Comptes de scolarité d'ouverture (OpeningBalances) : facture libre du socle en pension, compte hi_student_accounts sans grille. */
final class OpeningBalanceWriter implements OpeningBalances
{
    /** Identifiant de grille des comptes repris : aucune grille ne leur correspond. */
    public const NO_GRID = '00000000000000000000000000';

    public function __construct(
        private readonly BillingRepository $repository,
        private readonly BillingAccess $access,
        private readonly InvoiceIssuer $invoices,
        private readonly AuditLog $audit,
    ) {
    }

    public function open(string $tenantId, string $establishmentId, string $actorId, string $personId, string $academicYearId, string $schoolId, string $programId, ?string $levelId, ?string $studyMode, ?string $enrolmentRef, array $installments): array
    {
        $existing = $this->find($tenantId, $establishmentId, $personId, $academicYearId, $programId);
        if ($existing !== null) {
            $invoice = $this->invoices->find($tenantId, $establishmentId, (string) $existing['invoice_id']);

            return ['account_id' => (string) $existing['id'], 'invoice_id' => (string) $existing['invoice_id'], 'net_xaf' => $invoice->netXaf ?? 0, 'created' => false];
        }
        if ($installments === []) {
            throw HttpException::validation(['installments' => ['Au moins une tranche est exigée.']]);
        }
        $lines = [];
        $count = count($installments);
        foreach ($installments as $index => $term) {
            if ($term['amount_xaf'] <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $term['due_on']) !== 1) {
                throw HttpException::validation(['installments' => ['Chaque tranche exige une date AAAA-MM-JJ et un montant entier positif.']]);
            }
            $lines[] = new ChargeLine(HigherFees::PENSION, $term['due_on'], $term['amount_xaf'], sprintf('Solde d\'ouverture (reprise) · tranche %d/%d', $index + 1, $count));
        }
        $id = (string) new Ulid();
        $invoice = $this->invoices->forCharges(new ChargeInvoice($tenantId, $establishmentId, $actorId, $personId, $academicYearId, 'hi-open-' . $id, $lines, null, $levelId ?? $programId));
        $this->repository->insert(BillingRepository::ACCOUNTS, [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'academic_year_id' => $academicYearId,
            'person_id' => $personId, 'enrolment_ref' => $enrolmentRef, 'school_id' => $schoolId, 'program_id' => $programId, 'level_id' => $levelId,
            'study_mode' => $studyMode, 'grid_id' => self::NO_GRID, 'plan_id' => null, 'sponsor_id' => null, 'invoice_id' => $invoice->id,
            'created_by' => $actorId, 'created_at' => $this->access->now(),
        ]);
        $this->audit->append($tenantId, $actorId, 'higher.account.opening_balance', 'hi_student_account', $id, null, [
            'person_id' => $personId, 'invoice_id' => $invoice->id, 'net_xaf' => $invoice->netXaf, 'installments' => $count,
        ]);

        return ['account_id' => $id, 'invoice_id' => $invoice->id, 'net_xaf' => $invoice->netXaf, 'created' => true];
    }

    public function exists(string $tenantId, string $establishmentId, string $personId, string $academicYearId, string $programId): bool
    {
        return $this->find($tenantId, $establishmentId, $personId, $academicYearId, $programId) !== null;
    }

    /** @return array<string, mixed>|null */
    private function find(string $tenantId, string $establishmentId, string $personId, string $academicYearId, string $programId): ?array
    {
        return $this->repository->list(BillingRepository::ACCOUNTS, $tenantId, $establishmentId, [
            'academic_year_id' => $academicYearId, 'person_id' => $personId, 'program_id' => $programId,
        ], limit: 1)[0] ?? null;
    }
}
