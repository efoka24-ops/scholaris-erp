<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Application;

use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Billing\Domain\PaymentTerms;
use Scholaris\Modules\Apps\Higher\Billing\Domain\ScholarshipRules;
use Scholaris\Modules\Apps\Higher\Billing\Infrastructure\BillingRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitSettingsReader;

/**
 * Bourses et réductions (EX-FIN-03, RG-FIN-1), échéanciers individuels négociés (EX-FIN-02) et tiers payeurs (EX-FIN-04, S13).
 * Bourse et échéancier : demandés, puis validés par un comptable (billing.manage) qui n'est pas le demandeur.
 */
final class ScholarshipService
{
    public const TARGETS_KEY = 'higher.billing.discount_targets';
    public const CAP_KEY = 'billing.discount_cap_percent';
    public const RATES_KEY = 'higher.scholarship.rates';

    public function __construct(
        private readonly BillingRepository $repository,
        private readonly BillingAccess $access,
        private readonly AccountService $accounts,
        private readonly InvoiceIssuer $invoices,
        private readonly OrgUnitSettingsReader $settings,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array{scholarship_kind: string, calc_mode: ?string, rate_value: ?int, applies_to: ?string, reason: string} $input
     * @return array<string, mixed>
     */
    public function request(Principal $principal, string $establishmentId, string $accountId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        $account = $this->accounts->row($tenantId, $establishmentId, $accountId);
        $this->access->assertWritable($principal, $perimeter, $account, 'hi_student_account', $accountId);
        $school = (string) $account['school_id'];

        $kind = $input['scholarship_kind'];
        $appliesTo = $input['applies_to'] ?? 'tuition';
        $targets = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, self::TARGETS_KEY, ScholarshipRules::DEFAULT_TARGETS);
        if (!is_array($targets) || !in_array($appliesTo, $targets, true)) {
            throw HttpException::validation(['applies_to' => ['Les bourses et remises portent sur la pension seulement (RG-FIN-1).']]);
        }
        $mode = $input['calc_mode'] ?? 'percent';
        $value = $input['rate_value'] ?? $this->defaultRate($tenantId, $establishmentId, $school, $kind)
            ?? throw HttpException::validation(['rate_value' => ['Aucun taux par défaut pour ce type de bourse : indiquez rate_value.']]);
        if ($mode === 'percent' && $value > 100) {
            throw HttpException::validation(['rate_value' => ['Un pourcentage ne dépasse pas 100.']]);
        }

        $invoice = $this->invoices->find($tenantId, $establishmentId, (string) $account['invoice_id']) ?? throw HttpException::notFound();
        $base = 0;
        $granted = 0;
        foreach ($invoice->lines as $line) {
            if ($appliesTo === 'all' || $line['kind'] === $appliesTo) {
                $base += $line['gross_xaf'];
                $granted += $line['discount_xaf'];
            }
        }
        if ($base === 0) {
            throw HttpException::validation(['applies_to' => ['La facture ne porte aucun frais de ce type.']]);
        }
        $amount = ScholarshipRules::amount($mode, $value, $base);
        $pending = array_sum(array_map(static fn (array $s): int => (int) $s['amount_xaf'], $this->repository->list(BillingRepository::SCHOLARSHIPS, $tenantId, $establishmentId, ['account_id' => $accountId, 'status' => 'pending'])));
        $cap = $this->capPercent($tenantId, $establishmentId, $school);
        if (ScholarshipRules::exceedsCap($granted + $pending + $amount, $base, $cap)) {
            throw HttpException::validation(['rate_value' => [sprintf(
                'Plafond des bourses et remises dépassé : %d FCFA cumulés pour un plafond de %d %% de %d FCFA.',
                $granted + $pending + $amount,
                $cap,
                $base,
            )]]);
        }

        $id = (string) new Ulid();
        $this->repository->insert(BillingRepository::SCHOLARSHIPS, [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'account_id' => $accountId,
            'person_id' => (string) $account['person_id'], 'school_id' => $school, 'program_id' => $account['program_id'],
            'scholarship_kind' => $kind, 'calc_mode' => $mode, 'rate_value' => $value, 'applies_to' => $appliesTo, 'amount_xaf' => $amount,
            'reason' => $input['reason'], 'status' => 'pending', 'requested_by' => $principal->userId, 'decided_by' => null,
            'decided_at' => null, 'created_at' => $this->access->now(),
        ]);
        $this->audit->append($tenantId, $principal->userId, 'higher.scholarship.requested', 'hi_scholarship', $id, null, ['kind' => $kind, 'mode' => $mode, 'value' => $value, 'amount_xaf' => $amount]);

        return $this->resource($this->find($tenantId, $establishmentId, $id));
    }

    /**
     * Validation par un comptable distinct du demandeur : la remise est appliquée à la facture (répartie sur les tranches restantes).
     *
     * @return array<string, mixed>
     */
    public function decide(Principal $principal, string $establishmentId, string $id, bool $approve, ?string $note = null): array
    {
        $tenantId = $principal->tenantId;
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        $row = $this->find($tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_scholarship', $id);
        if ($row['status'] !== 'pending') {
            throw HttpException::conflict('Cette bourse a déjà été traitée.');
        }
        if ($row['requested_by'] === $principal->userId) {
            throw HttpException::forbidden('La bourse doit être validée par un autre comptable que le demandeur.');
        }
        $values = ['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $principal->userId, 'decided_at' => $this->access->now()];
        if ($approve) {
            $account = $this->accounts->row($tenantId, $establishmentId, (string) $row['account_id']);
            $before = $this->invoices->find($tenantId, $establishmentId, (string) $account['invoice_id'])->discountXaf ?? 0;
            $after = $this->invoices->addDiscount(
                $tenantId,
                $establishmentId,
                $principal->userId,
                (string) $account['invoice_id'],
                ScholarshipRules::SOCLE_KIND[(string) $row['scholarship_kind']],
                (string) $row['calc_mode'],
                (int) $row['rate_value'],
                (string) $row['applies_to'],
                self::reasonFor((string) $row['scholarship_kind'], (string) $row['reason']),
            );
            $values['amount_xaf'] = $after->discountXaf - $before;
        }
        $this->repository->update(BillingRepository::SCHOLARSHIPS, $tenantId, $establishmentId, $id, $values);
        $this->audit->append($tenantId, $principal->userId, 'higher.scholarship.' . $values['status'], 'hi_scholarship', $id, ['status' => 'pending'], ['status' => $values['status'], 'note' => $note]);

        return $this->resource($this->find($tenantId, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function forAccount(Principal $principal, string $establishmentId, string $accountId): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE);
        $account = $this->accounts->row($principal->tenantId, $establishmentId, $accountId);
        $this->access->assertReadable($perimeter, $account);

        return array_map($this->resource(...), $this->repository->list(BillingRepository::SCHOLARSHIPS, $principal->tenantId, $establishmentId, ['account_id' => $accountId]));
    }

    /**
     * Échéancier individuel négocié : tranches datées et pondérées de la pension, en attente de validation.
     *
     * @param array{person_id: string, academic_year_id: string, school_id: string, program_id: ?string, terms: mixed, reason: string} $input
     * @return array<string, mixed>
     */
    public function requestPlan(Principal $principal, string $establishmentId, array $input): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE, BillingAccess::CASH);
        $id = (string) new Ulid();
        $this->access->assertWritable($principal, $perimeter, $input, 'hi_payment_plan', $id);
        try {
            $terms = PaymentTerms::fromArray($input['terms']);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['terms' => [$e->getMessage()]]);
        }
        $this->repository->insert(BillingRepository::PLANS, [
            'id' => $id, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'academic_year_id' => $input['academic_year_id'],
            'person_id' => $input['person_id'], 'school_id' => $input['school_id'], 'program_id' => $input['program_id'],
            'terms_json' => json_encode($terms->toArray(), JSON_THROW_ON_ERROR), 'reason' => $input['reason'], 'status' => 'pending',
            'requested_by' => $principal->userId, 'decided_by' => null, 'decided_at' => null, 'created_at' => $this->access->now(),
        ]);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.payment_plan.requested', 'hi_payment_plan', $id, null, ['terms' => $terms->toArray()]);

        return $this->planResource($this->plan($principal->tenantId, $establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function decidePlan(Principal $principal, string $establishmentId, string $id, bool $approve): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        $row = $this->plan($principal->tenantId, $establishmentId, $id);
        $this->access->assertWritable($principal, $perimeter, $row, 'hi_payment_plan', $id);
        if ($row['status'] !== 'pending') {
            throw HttpException::conflict('Cet échéancier a déjà été traité.');
        }
        if ($row['requested_by'] === $principal->userId) {
            throw HttpException::forbidden('L\'échéancier négocié doit être validé par un autre comptable que le demandeur.');
        }
        $status = $approve ? 'approved' : 'rejected';
        $this->repository->update(BillingRepository::PLANS, $principal->tenantId, $establishmentId, $id, ['status' => $status, 'decided_by' => $principal->userId, 'decided_at' => $this->access->now()]);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.payment_plan.' . $status, 'hi_payment_plan', $id, ['status' => 'pending'], ['status' => $status]);

        return $this->planResource($this->plan($principal->tenantId, $establishmentId, $id));
    }

    /**
     * Tiers payeur (entreprise, bailleur, parrain) au nom duquel les factures sont établies (S13).
     *
     * @param array{sponsor_kind: string, name: string, reference: ?string, contact: ?string} $input
     * @return array<string, mixed>
     */
    public function createSponsor(Principal $principal, string $establishmentId, array $input): array
    {
        $this->access->perimeter($principal, $establishmentId, BillingAccess::MANAGE);
        try {
            new InvoicePayer($input['sponsor_kind'], $input['name'], $input['reference']);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['name' => [$e->getMessage()]]);
        }
        $id = (string) new Ulid();
        $this->repository->insert(BillingRepository::SPONSORS, [
            'id' => $id, 'tenant_id' => $principal->tenantId, 'establishment_id' => $establishmentId, 'sponsor_kind' => $input['sponsor_kind'],
            'name' => $input['name'], 'reference' => $input['reference'], 'contact' => $input['contact'], 'created_by' => $principal->userId,
            'created_at' => $this->access->now(),
        ]);
        $this->audit->append($principal->tenantId, $principal->userId, 'higher.sponsor.created', 'hi_sponsor', $id, null, ['name' => $input['name']]);

        return self::sponsorResource($this->repository->find(BillingRepository::SPONSORS, $principal->tenantId, $establishmentId, $id) ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function sponsors(Principal $principal, string $establishmentId): array
    {
        $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE);

        return array_map(self::sponsorResource(...), $this->repository->list(BillingRepository::SPONSORS, $principal->tenantId, $establishmentId));
    }

    /**
     * Comptes pris en charge par le tiers (factures à son nom) et total restant dû par lui.
     *
     * @return array<string, mixed>
     */
    public function sponsorStatement(Principal $principal, string $establishmentId, string $id): array
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, BillingAccess::READ, BillingAccess::MANAGE);
        $sponsor = $this->repository->find(BillingRepository::SPONSORS, $principal->tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Tiers payeur introuvable.');
        $accounts = [];
        $due = 0;
        foreach ($this->repository->list(BillingRepository::ACCOUNTS, $principal->tenantId, $establishmentId, ['sponsor_id' => $id], $this->access->filter($perimeter)) as $row) {
            $view = $this->accounts->view($principal->tenantId, $establishmentId, $row);
            $due += $view->balanceXaf;
            $accounts[] = ['account_id' => $view->id, 'person_id' => $view->personId, 'invoice_id' => $view->invoiceId, 'net_xaf' => $view->netXaf, 'balance_xaf' => $view->balanceXaf];
        }

        return self::sponsorResource($sponsor) + ['accounts' => $accounts, 'balance_xaf' => $due];
    }

    private function defaultRate(string $tenantId, string $establishmentId, string $school, string $kind): ?int
    {
        $rates = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, self::RATES_KEY, ScholarshipRules::DEFAULT_RATES);
        $rate = is_array($rates) ? ($rates[$kind] ?? null) : null;

        return is_int($rate) && $rate >= 0 && $rate <= 100 ? $rate : null;
    }

    private function capPercent(string $tenantId, string $establishmentId, string $school): int
    {
        $cap = $this->settings->valueForOrgUnit($tenantId, $establishmentId, $school, self::CAP_KEY, ScholarshipRules::DEFAULT_CAP_PERCENT);

        return is_int($cap) && $cap >= 0 && $cap <= 100 ? $cap : ScholarshipRules::DEFAULT_CAP_PERCENT;
    }

    private static function reasonFor(string $kind, string $reason): string
    {
        $prefix = ['merit' => 'Bourse de mérite', 'staff' => 'Réduction personnel', 'sibling' => 'Réduction fratrie', 'partnership' => 'Partenariat entreprise'][$kind] ?? 'Bourse';

        return mb_substr($prefix . ' : ' . $reason, 0, 255);
    }

    /** @return array<string, mixed> */
    private function find(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->find(BillingRepository::SCHOLARSHIPS, $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Bourse introuvable.');
    }

    /** @return array<string, mixed> */
    private function plan(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->repository->find(BillingRepository::PLANS, $tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Échéancier introuvable.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'account_id' => (string) $row['account_id'], 'person_id' => (string) $row['person_id'],
            'scholarship_kind' => (string) $row['scholarship_kind'], 'calc_mode' => (string) $row['calc_mode'], 'rate_value' => (int) $row['rate_value'],
            'applies_to' => (string) $row['applies_to'], 'amount_xaf' => (int) $row['amount_xaf'], 'reason' => (string) $row['reason'],
            'status' => (string) $row['status'], 'requested_by' => (string) $row['requested_by'], 'decided_by' => $row['decided_by'],
            'decided_at' => $row['decided_at'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function planResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'academic_year_id' => (string) $row['academic_year_id'],
            'school_id' => (string) $row['school_id'], 'program_id' => $row['program_id'], 'terms' => json_decode((string) $row['terms_json'], true),
            'reason' => (string) $row['reason'], 'status' => (string) $row['status'], 'requested_by' => (string) $row['requested_by'],
            'decided_by' => $row['decided_by'], 'decided_at' => $row['decided_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sponsorResource(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''), 'sponsor_kind' => (string) ($row['sponsor_kind'] ?? ''), 'name' => (string) ($row['name'] ?? ''),
            'reference' => $row['reference'] ?? null, 'contact' => $row['contact'] ?? null,
        ];
    }
}
