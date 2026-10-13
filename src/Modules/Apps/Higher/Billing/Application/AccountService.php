<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccounts;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentAccountView;
use Scholaris\Modules\Apps\Higher\Billing\Contracts\StudentPayments;
use Scholaris\Modules\Apps\Higher\Billing\Domain\AccountLedger;
use Scholaris\Modules\Apps\Higher\Billing\Domain\HigherFees;
use Scholaris\Modules\Apps\Higher\Billing\Domain\PaymentTerms;
use Scholaris\Modules\Apps\Higher\Billing\Infrastructure\BillingRepository;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOutcome;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;

/**
 * Compte de scolarité d'un étudiant pour une année et une filière : une facture libre du socle (InvoiceIssuer::forCharges, D4)
 * construite depuis la grille applicable et l'échéancier (par défaut ou négocié et validé), éventuellement au nom d'un tiers
 * (S13). Paiements par PaymentRequester : affectation du socle (RG-FIN-2 : inscription, tranches échues, tranches à venir),
 * reçu numéroté du socle (DOC-27) et notification NOT-10.
 */
final class AccountService implements StudentAccounts, StudentPayments
{
    public const CHANNELS = ['cash', 'orange_money', 'mtn_momo', 'transfer', 'card'];
    public const MOBILE = ['orange_money', 'mtn_momo'];

    public function __construct(
        private readonly BillingRepository $repository,
        private readonly BillingAccess $access,
        private readonly FeeGridService $grids,
        private readonly InvoiceIssuer $invoices,
        private readonly PaymentRequester $payments,
        private readonly HigherNotifier $notifier,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array{person_id: string, academic_year_id: string, school_id: string, program_id: ?string, level_id: ?string,
     *              study_mode: ?string, enrolment_ref: ?string, plan_id: ?string, sponsor_id: ?string} $input
     * @return array<string, mixed>
     */
    public function open(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        $id = (string) new Ulid();
        $this->access->assertWritable($principal, $perimeter, $input, 'hi_student_account', $id);

        $existing = $this->repository->list(BillingRepository::ACCOUNTS, $tenantId, $establishmentId, [
            'academic_year_id' => $input['academic_year_id'], 'person_id' => $input['person_id'], 'program_id' => $input['program_id'],
        ], limit: 1);
        if ($existing !== []) {
            throw HttpException::conflict('Cet étudiant a déjà un compte de scolarité pour cette année et cette filière.');
        }
        $grid = $this->grids->resolve($tenantId, $establishmentId, $input['academic_year_id'], $input['school_id'], $input['program_id'], $input['level_id'], $input['study_mode'])
            ?? throw HttpException::validation(['school_id' => ['Aucune grille de frais active ne correspond à cette école, filière, niveau et mode.']]);
        $terms = PaymentTerms::fromArray(json_decode((string) $grid['terms_json'], true));
        if ($input['plan_id'] !== null) {
            $plan = $this->repository->find(BillingRepository::PLANS, $tenantId, $establishmentId, $input['plan_id']);
            if ($plan === null || $plan['person_id'] !== $input['person_id'] || $plan['academic_year_id'] !== $input['academic_year_id']) {
                throw HttpException::validation(['plan_id' => ['Échéancier négocié introuvable pour cet étudiant et cette année.']]);
            }
            if ($plan['status'] !== 'approved') {
                throw HttpException::validation(['plan_id' => ['L\'échéancier négocié doit être validé par le comptable avant l\'ouverture du compte.']]);
            }
            $terms = PaymentTerms::fromArray(json_decode((string) $plan['terms_json'], true));
        }
        $payer = null;
        if ($input['sponsor_id'] !== null) {
            $sponsor = $this->repository->find(BillingRepository::SPONSORS, $tenantId, $establishmentId, $input['sponsor_id'])
                ?? throw HttpException::validation(['sponsor_id' => ['Tiers payeur introuvable.']]);
            $payer = new InvoicePayer((string) $sponsor['sponsor_kind'], (string) $sponsor['name'], isset($sponsor['reference']) ? (string) $sponsor['reference'] : null);
        }

        $invoice = $this->invoices->forCharges(new ChargeInvoice(
            $tenantId,
            $establishmentId,
            $principal->userId,
            $input['person_id'],
            $input['academic_year_id'],
            'hi-' . $id,
            self::chargeLines(json_decode((string) $grid['fees_json'], true), $terms),
            $payer,
            $input['level_id'] ?? $input['program_id'],
        ));

        $this->repository->insert(BillingRepository::ACCOUNTS, [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'academic_year_id' => $input['academic_year_id'],
            'person_id' => $input['person_id'], 'enrolment_ref' => $input['enrolment_ref'], 'school_id' => $input['school_id'],
            'program_id' => $input['program_id'], 'level_id' => $input['level_id'], 'study_mode' => $input['study_mode'],
            'grid_id' => (string) $grid['id'], 'plan_id' => $input['plan_id'], 'sponsor_id' => $input['sponsor_id'],
            'invoice_id' => $invoice->id, 'created_by' => $principal->userId, 'created_at' => $this->access->now(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'higher.account.opened', 'hi_student_account', $id, null, [
            'person_id' => $input['person_id'], 'invoice_id' => $invoice->id, 'net_xaf' => $invoice->netXaf, 'grid_id' => $grid['id'],
        ]);

        return $this->view($tenantId, $establishmentId, $this->row($tenantId, $establishmentId, $id))->toArray();
    }

    /**
     * Lignes de la facture : la pension est découpée selon l'échéancier ; les autres frais ont une seule échéance
     * (leur date propre, sinon celle de la première tranche).
     *
     * @param mixed $fees
     * @return list<ChargeLine>
     */
    public static function chargeLines(mixed $fees, PaymentTerms $terms): array
    {
        $lines = [];
        foreach (is_array($fees) ? $fees : [] as $fee) {
            $code = (string) $fee['fee_code'];
            $amount = (int) $fee['amount_xaf'];
            if ($amount <= 0) {
                continue;
            }
            if ($code === HigherFees::PENSION) {
                $parts = $terms->split($amount);
                $count = count($parts);
                foreach ($terms->toArray() as $index => $term) {
                    $lines[] = new ChargeLine($code, $term['due_on'], $parts[$index], sprintf('%s · tranche %d/%d', HigherFees::labelOf($code), $index + 1, $count));
                }
                continue;
            }
            $lines[] = new ChargeLine($code, is_string($fee['due_on'] ?? null) ? $fee['due_on'] : $terms->firstDueOn(), $amount, HigherFees::labelOf($code));
        }
        if ($lines === []) {
            throw HttpException::validation(['fees' => ['La grille ne contient aucun montant à facturer.']]);
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE, BillingAccess::CASH);
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        $this->access->assertReadable($perimeter, $row);

        return $this->view($principal->tenantId, $establishmentId, $row)->toArray() + ['ledger' => $this->ledgerSummary($principal->tenantId, $establishmentId, $row)];
    }

    /**
     * @param array<string, ?string> $filters
     * @return list<array<string, mixed>>
     */
    public function index(Principal $principal, string $establishmentId, array $filters): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE, BillingAccess::CASH);
        $rows = $this->repository->list(BillingRepository::ACCOUNTS, $principal->tenantId, $establishmentId, array_filter($filters, static fn (?string $v): bool => $v !== null), $this->access->filter($perimeter));

        return array_map(fn (array $row): array => $this->view($principal->tenantId, $establishmentId, $row)->toArray(), $rows);
    }

    /**
     * @param array{amount_xaf: int, channel: string, idempotency_key: string, payer_phone: ?string, external_reference: ?string} $input
     * @return array<string, mixed>
     */
    public function pay(Principal $principal, string $establishmentId, string $id, array $input): array
    {
        $perimeter = $input['channel'] === 'cash'
            ? $this->access->perimeter($principal, $establishmentId, BillingAccess::CASH)
            : $this->access->perimeter($principal, $establishmentId, BillingAccess::CASH, BillingAccess::MANAGE);
        $row = $this->row($principal->tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_student_account', $id);

        return $this->record($principal->tenantId, $establishmentId, $principal->userId, $row, $input);
    }

    public function requestMobilePayment(string $tenantId, string $personId, string $accountId, int $amountXaf, string $channel, string $payerPhone, string $idempotencyKey, string $actorId): array
    {
        $row = null;
        foreach ($this->repository->accountsOfPerson($tenantId, $personId) as $account) {
            if ($account['id'] === $accountId) {
                $row = $account;
            }
        }
        if ($row === null) {
            throw HttpException::notFound('Compte de scolarité introuvable.');
        }
        if (!in_array($channel, self::MOBILE, true)) {
            throw HttpException::validation(['channel' => ['Depuis le portail : orange_money ou mtn_momo.']]);
        }

        return $this->record($tenantId, (string) $row['establishment_id'], $actorId, $row, [
            'amount_xaf' => $amountXaf, 'channel' => $channel, 'idempotency_key' => $idempotencyKey, 'payer_phone' => $payerPhone, 'external_reference' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{amount_xaf: int, channel: string, idempotency_key: string, payer_phone: ?string, external_reference: ?string} $input
     * @return array{payment: array<string, mixed>, account: array<string, mixed>}
     */
    private function record(string $tenantId, string $establishmentId, ?string $actorId, array $row, array $input): array
    {
        $outcome = $this->payments->request(new PaymentOrder(
            $tenantId,
            $establishmentId,
            $actorId,
            (string) $row['invoice_id'],
            $input['amount_xaf'],
            $input['channel'],
            $input['idempotency_key'],
            $input['payer_phone'],
            $input['external_reference'],
        ));
        if ($this->repository->paymentByPaymentId($tenantId, $outcome->paymentId) === null) {
            $this->repository->insert(BillingRepository::PAYMENTS, [
                'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'account_id' => (string) $row['id'],
                'person_id' => (string) $row['person_id'], 'payment_id' => $outcome->paymentId, 'channel' => $outcome->channel,
                'amount_xaf' => $outcome->amountXaf, 'status' => $outcome->status, 'receipt_id' => $outcome->receiptId,
                'receipt_number' => $outcome->receiptNumber, 'recorded_by' => $actorId, 'recorded_at' => $this->access->now(),
            ]);
            if ($outcome->status === PaymentOutcome::CONFIRMED) {
                $this->notifyReceived($tenantId, $establishmentId, $row, $outcome);
            }
        }

        return [
            'payment' => [
                'id' => $outcome->paymentId, 'status' => $outcome->status, 'amount_xaf' => $outcome->amountXaf, 'channel' => $outcome->channel,
                'receipt_id' => $outcome->receiptId, 'receipt_number' => $outcome->receiptNumber, 'replayed' => $outcome->replayed,
            ],
            'account' => $this->view($tenantId, $establishmentId, $row)->toArray(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function notifyReceived(string $tenantId, string $establishmentId, array $row, PaymentOutcome $outcome): void
    {
        $balance = $this->invoices->find($tenantId, $establishmentId, (string) $row['invoice_id'])->balanceXaf ?? 0;
        $this->notifier->notify($tenantId, $establishmentId, 'NOT-10', (string) $row['person_id'], [
            'amount' => $outcome->amountXaf, 'receipt_number' => (string) $outcome->receiptNumber, 'balance' => $balance,
        ], 'payment:' . $outcome->paymentId);
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return array_map(fn (array $row): StudentAccountView => $this->view($tenantId, (string) $row['establishment_id'], $row), $this->repository->accountsOfPerson($tenantId, $personId));
    }

    public function forEstablishment(string $tenantId, string $establishmentId): array
    {
        return array_map(fn (array $row): StudentAccountView => $this->view($tenantId, $establishmentId, $row), $this->repository->list(BillingRepository::ACCOUNTS, $tenantId, $establishmentId, limit: 1000));
    }

    public function activeScopes(): array
    {
        return $this->repository->activeScopes();
    }

    /** @return array<string, mixed> */
    public function row(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->find(BillingRepository::ACCOUNTS, $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Compte de scolarité introuvable.');
    }

    /** @param array<string, mixed> $row */
    public function view(string $tenantId, string $establishmentId, array $row): StudentAccountView
    {
        $this->refreshPending($tenantId, $establishmentId, $row);
        $invoice = $this->invoices->find($tenantId, $establishmentId, (string) $row['invoice_id'])
            ?? throw HttpException::notFound('Facture du compte introuvable.');
        $ledger = new AccountLedger(array_map(static fn (array $i): array => [
            'id' => $i['id'], 'label' => $i['label'], 'due_on' => $i['due_on'], 'amount_xaf' => $i['amount_xaf'], 'paid_xaf' => $i['paid_xaf'],
        ], $invoice->installments), $this->access->today());
        $receipts = array_map(static fn (array $p): array => [
            'payment_id' => (string) $p['payment_id'], 'receipt_number' => isset($p['receipt_number']) ? (string) $p['receipt_number'] : null,
            'amount_xaf' => (int) $p['amount_xaf'], 'channel' => (string) $p['channel'], 'status' => (string) $p['status'], 'recorded_at' => (string) $p['recorded_at'],
        ], $this->repository->paymentsOfAccount($tenantId, (string) $row['id']));

        return new StudentAccountView(
            (string) $row['id'],
            $establishmentId,
            (string) $row['academic_year_id'],
            (string) $row['person_id'],
            (string) $row['school_id'],
            isset($row['program_id']) ? (string) $row['program_id'] : null,
            isset($row['level_id']) ? (string) $row['level_id'] : null,
            (string) $row['invoice_id'],
            $invoice->payer?->name,
            $invoice->netXaf,
            $invoice->paidXaf,
            $invoice->balanceXaf,
            $ledger->isUpToDate(),
            $ledger->lines(),
            $receipts,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ledgerSummary(string $tenantId, string $establishmentId, array $row): array
    {
        $invoice = $this->invoices->find($tenantId, $establishmentId, (string) $row['invoice_id']) ?? throw HttpException::notFound();
        $ledger = new AccountLedger(array_map(static fn (array $i): array => [
            'id' => $i['id'], 'label' => $i['label'], 'due_on' => $i['due_on'], 'amount_xaf' => $i['amount_xaf'], 'paid_xaf' => $i['paid_xaf'],
        ], $invoice->installments), $this->access->today());

        return [
            'today' => $this->access->today(), 'overdue_xaf' => $ledger->overdueXaf(), 'next_due' => $ledger->nextDue(),
            'gross_xaf' => $invoice->grossXaf, 'discount_xaf' => $invoice->discountXaf,
            'lines' => array_map(static fn (array $l): array => [
                'fee_code' => $l['fee_code'], 'label' => $l['label'], 'gross_xaf' => $l['gross_xaf'], 'discount_xaf' => $l['discount_xaf'],
                'net_xaf' => $l['net_xaf'], 'paid_xaf' => $l['paid_xaf'],
            ], $invoice->lines),
        ];
    }

    /**
     * Paiements mobile money en attente : l'état confirmé par l'opérateur est relu ; le reçu et NOT-10 suivent la confirmation.
     *
     * @param array<string, mixed> $row
     */
    private function refreshPending(string $tenantId, string $establishmentId, array $row): void
    {
        foreach ($this->repository->paymentsOfAccount($tenantId, (string) $row['id']) as $payment) {
            if ($payment['status'] !== PaymentOutcome::PENDING) {
                continue;
            }
            $outcome = $this->payments->status($tenantId, $establishmentId, (string) $payment['payment_id']);
            if ($outcome === null || $outcome->status === PaymentOutcome::PENDING) {
                continue;
            }
            $this->repository->update(BillingRepository::PAYMENTS, $tenantId, $establishmentId, (string) $payment['id'], [
                'status' => $outcome->status, 'receipt_id' => $outcome->receiptId, 'receipt_number' => $outcome->receiptNumber,
            ]);
            if ($outcome->status === PaymentOutcome::CONFIRMED) {
                $this->notifyReceived($tenantId, $establishmentId, $row, $outcome);
            }
        }
    }
}
