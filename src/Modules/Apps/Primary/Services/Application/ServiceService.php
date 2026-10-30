<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Services\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;

/**
 * Services aux familles (EX-PRI-36 ; Q12, à confirmer) : forfaits de cantine, garderie, transport (`primary.services.kinds`) liés à
 * un frais du catalogue Billing ; abonnements des élèves inscrits (capacité respectée) ; facturation par cycle : une facture par
 * abonnement et par cycle (`cycle_key`), jamais deux (unicité locale + référence idempotente du socle). Suivi quotidien hors V1.
 */
final class ServiceService
{
    public const PLANS = 'pri_service_plans';
    public const SUBSCRIPTIONS = 'pri_service_subscriptions';
    public const CHARGES = 'pri_service_charges';
    public const READ = ['primary.services.read', 'primary.services.manage'];
    public const MANAGE = ['primary.services.manage'];
    public const CYCLES = ['term', 'month', 'year'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly PrimarySettings $settings,
        private readonly EnrollmentDirectory $enrollments,
        private readonly InvoiceIssuer $invoices,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array{kinds: array<string, array<string, mixed>>, plans: list<array<string, mixed>>} */
    public function plans(Principal $principal, string $establishmentId): array
    {
        $this->authorise($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $plans = array_map(fn (array $r): array => self::planResource($r) + ['subscribers' => $this->store->count(self::SUBSCRIPTIONS, $t, $establishmentId, ['plan_id' => (string) $r['id'], 'status' => 'active'])], $this->store->select(self::PLANS, $t, $establishmentId, [], 'service_kind, label', 500));

        return ['kinds' => $this->settings->labels($t, $establishmentId, 'primary.services.kinds'), 'plans' => $plans];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createPlan(Principal $principal, string $establishmentId, array $input): array
    {
        $this->authorise($principal, $establishmentId, self::MANAGE);
        $t = $principal->tenantId;
        $kinds = $this->settings->labels($t, $establishmentId, 'primary.services.kinds');
        if (!isset($kinds[(string) $input['service_kind']])) {
            throw HttpException::validation(['service_kind' => ['Valeurs possibles : ' . implode(', ', array_keys($kinds)) . ' (paramètre primary.services.kinds).']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::PLANS, $t, $establishmentId, [
            'id' => $id, 'service_kind' => (string) $input['service_kind'], 'label' => (string) $input['label'], 'fee_code' => (string) $input['fee_code'],
            'billing_cycle' => (string) $input['billing_cycle'], 'capacity' => $input['capacity'] ?? null, 'route_label' => $input['route_label'] ?? null,
            'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.service.plan_created', self::PLANS, $id, null, ['service_kind' => (string) $input['service_kind']]);

        return self::planResource($this->store->findOrFail(self::PLANS, $t, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updatePlan(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $this->authorise($principal, $establishmentId, self::MANAGE);
        $t = $principal->tenantId;
        $this->store->findOrFail(self::PLANS, $t, $establishmentId, $id, 'Service introuvable.');
        if (array_key_exists('active', $changes)) {
            $changes['active'] = $changes['active'] ? 1 : 0;
        }
        if ($changes !== []) {
            $this->store->update(self::PLANS, $t, $establishmentId, $changes + ['updated_at' => $this->clock->now()], ['id' => $id]);
        }

        return self::planResource($this->store->findOrFail(self::PLANS, $t, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(Principal $principal, string $establishmentId, string $planId): array
    {
        $this->authorise($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $this->store->findOrFail(self::PLANS, $t, $establishmentId, $planId, 'Service introuvable.');
        $rows = $this->store->select(self::SUBSCRIPTIONS, $t, $establishmentId, ['plan_id' => $planId], 'starts_on, id', 5000);
        $names = $this->classes->names($t, array_map(static fn (array $r): string => (string) $r['person_id'], $rows));

        return array_map(static fn (array $r): array => self::subscriptionResource($r) + ['name' => $names[(string) $r['person_id']] ?? (string) $r['person_id']], $rows);
    }

    /** @return array<string, mixed> */
    public function subscribe(Principal $principal, string $establishmentId, string $planId, string $personId, string $yearId, string $startsOn): array
    {
        $this->authorise($principal, $establishmentId, self::MANAGE);
        $t = $principal->tenantId;
        $plan = $this->store->findOrFail(self::PLANS, $t, $establishmentId, $planId, 'Service introuvable.');
        if ((int) $plan['active'] !== 1) {
            throw HttpException::conflict('Ce service n\'est plus proposé.');
        }
        $enrolled = false;
        foreach ($this->enrollments->forPerson($t, $personId) as $e) {
            $enrolled = $enrolled || ($e->establishmentId === $establishmentId && $e->academicYearId === $yearId && in_array($e->status, ['active', 'conditional'], true));
        }
        if (!$enrolled) {
            throw HttpException::validation(['person_id' => ['Cet élève n\'est pas inscrit dans l\'école pour cette année.']]);
        }
        if ($this->store->select(self::SUBSCRIPTIONS, $t, $establishmentId, ['plan_id' => $planId, 'person_id' => $personId, 'status' => 'active'], 'id', 1) !== []) {
            throw HttpException::conflict('Cet élève est déjà abonné à ce service.');
        }
        $active = $this->store->count(self::SUBSCRIPTIONS, $t, $establishmentId, ['plan_id' => $planId, 'status' => 'active']);
        if ($plan['capacity'] !== null && $active >= (int) $plan['capacity']) {
            throw HttpException::conflict('Capacité du service atteinte.');
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::SUBSCRIPTIONS, $t, $establishmentId, [
            'id' => $id, 'plan_id' => $planId, 'person_id' => $personId, 'academic_year_id' => $yearId, 'starts_on' => $startsOn, 'ends_on' => null,
            'status' => 'active', 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return self::subscriptionResource($this->store->findOrFail(self::SUBSCRIPTIONS, $t, $establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function end(Principal $principal, string $establishmentId, string $subscriptionId, string $endsOn): array
    {
        $this->authorise($principal, $establishmentId, self::MANAGE);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::SUBSCRIPTIONS, $t, $establishmentId, $subscriptionId, 'Abonnement introuvable.');
        if ($row['status'] !== 'active') {
            throw HttpException::conflict('Cet abonnement est déjà terminé.');
        }
        $this->store->update(self::SUBSCRIPTIONS, $t, $establishmentId, ['status' => 'ended', 'ends_on' => $endsOn, 'updated_at' => $this->clock->now()], ['id' => $subscriptionId]);

        return self::subscriptionResource($this->store->findOrFail(self::SUBSCRIPTIONS, $t, $establishmentId, $subscriptionId));
    }

    /** @return array{invoiced: int, already: int} une facture par abonnement actif et par cycle */
    public function invoice(Principal $principal, string $establishmentId, string $planId, string $cycleKey, string $dueOn): array
    {
        $this->authorise($principal, $establishmentId, self::MANAGE);
        $t = $principal->tenantId;
        $plan = $this->store->findOrFail(self::PLANS, $t, $establishmentId, $planId, 'Service introuvable.');
        $counts = ['invoiced' => 0, 'already' => 0];
        foreach ($this->store->select(self::SUBSCRIPTIONS, $t, $establishmentId, ['plan_id' => $planId, 'status' => 'active'], 'id', 5000) as $sub) {
            if ($this->store->select(self::CHARGES, $t, $establishmentId, ['subscription_id' => (string) $sub['id'], 'cycle_key' => $cycleKey], 'id', 1) !== []) {
                ++$counts['already'];
                continue;
            }
            $summary = $this->invoices->forCharges(new ChargeInvoice($t, $establishmentId, $principal->userId, (string) $sub['person_id'], (string) $sub['academic_year_id'], 'pri-svc:' . (string) $sub['id'] . ':' . $cycleKey, [new ChargeLine((string) $plan['fee_code'], $dueOn, null, (string) $plan['label'] . ' (' . $cycleKey . ')')]));
            $now = $this->clock->now();
            $this->store->insert(self::CHARGES, $t, $establishmentId, ['id' => (string) new Ulid(), 'subscription_id' => (string) $sub['id'], 'cycle_key' => $cycleKey, 'invoice_id' => $summary->id, 'created_at' => $now, 'updated_at' => $now]);
            ++$counts['invoiced'];
        }
        $this->access->audit($principal, 'primary.service.invoiced', self::PLANS, $planId, null, $counts + ['cycle_key' => $cycleKey]);

        return $counts;
    }

    /** @param list<string> $permissions */
    private function authorise(Principal $principal, string $establishmentId, array $permissions): void
    {
        $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        $this->access->assertWhole($principal, $perimeter, self::PLANS);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function planResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'service_kind' => (string) $row['service_kind'], 'label' => (string) $row['label'], 'fee_code' => (string) $row['fee_code'],
            'billing_cycle' => (string) $row['billing_cycle'], 'capacity' => $row['capacity'] === null ? null : (int) $row['capacity'],
            'route_label' => $row['route_label'] === null ? null : (string) $row['route_label'], 'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function subscriptionResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'plan_id' => (string) $row['plan_id'], 'person_id' => (string) $row['person_id'], 'academic_year_id' => (string) $row['academic_year_id'],
            'starts_on' => (string) $row['starts_on'], 'ends_on' => $row['ends_on'] === null ? null : (string) $row['ends_on'], 'status' => (string) $row['status'],
        ];
    }
}
