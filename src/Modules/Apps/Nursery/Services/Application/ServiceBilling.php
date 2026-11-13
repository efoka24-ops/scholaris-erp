<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Services\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;

/**
 * Facturation des services par le socle Billing (facture libre `forCharges`, une ligne : le frais du catalogue désigné par l'offre,
 * montant et libellé repris du catalogue). Un abonnement n'est facturé qu'UNE fois par cycle : référence stable
 * `nursery-service:<abonnement>:<cycle>` côté socle et ligne unique `nu_service_invoices` côté application. Cycle : mois civil
 * (AAAA-MM), trimestre ou année scolaire de l'établissement (périodes du socle). L'échéance est le début du cycle (ou de l'abonnement).
 */
final class ServiceBilling
{
    public const TABLE = 'nu_service_invoices';

    public function __construct(
        private readonly Store $store,
        private readonly ServiceCatalog $catalog,
        private readonly ChildDirectory $children,
        private readonly InvoiceIssuer $invoices,
        private readonly CurrentPeriod $periods,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /** @return array<string, mixed> facture du cycle qui contient `$on` (rendue telle quelle si déjà émise) */
    public function invoice(Principal $principal, Perimeter $perimeter, string $subscriptionId, string $on): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $subscription = $this->catalog->subscription($tenantId, $establishmentId, $subscriptionId);
        $learnerId = (string) $subscription['learner_id'];
        $section = $this->children->assertInPerimeter($perimeter, $learnerId);
        $this->access->assertWritable($principal, $perimeter, $section, 'service_subscription', $subscriptionId);
        $plan = $this->catalog->plan($tenantId, $establishmentId, (string) $subscription['plan_id']);
        [$cycle, $start, $end] = $this->cycle($tenantId, $establishmentId, (string) $plan['billing_cycle'], $on);
        if ((string) $subscription['starts_on'] > $end || ($subscription['ends_on'] !== null && (string) $subscription['ends_on'] < $start)) {
            throw HttpException::validation(['on' => ['L’abonnement ne couvre pas ce cycle (' . $cycle . ').']]);
        }
        $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, ['subscription_id' => $subscriptionId, 'billing_period' => $cycle], 'id', 1)[0] ?? null;
        if ($existing !== null) {
            return self::resource($existing) + ['already_invoiced' => true];
        }
        $enrollment = $this->children->currentEnrollment($tenantId, $establishmentId, $learnerId) ?? throw HttpException::conflict('Enfant sans inscription en cours : aucune facture de service.');
        $due = max($start, (string) $subscription['starts_on']);
        $summary = $this->invoices->forCharges(new ChargeInvoice(
            $tenantId,
            $establishmentId,
            $principal->userId,
            $learnerId,
            $enrollment->academicYearId,
            'nursery-service:' . $subscriptionId . ':' . $cycle,
            [new ChargeLine(null, $due, null, (string) $plan['label'] . ' (' . $cycle . ')', (string) $plan['fee_item_id'])],
            null,
            $enrollment->structureId,
        ));
        $id = (string) new Ulid();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'subscription_id' => $subscriptionId, 'billing_period' => $cycle, 'invoice_id' => $summary->id, 'amount_xaf' => $summary->netXaf,
            'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'nursery.service.invoiced', 'service_subscription', $subscriptionId, null, ['cycle' => $cycle, 'invoice_id' => $summary->id, 'net_xaf' => $summary->netXaf]);

        return self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id)) + ['already_invoiced' => false];
    }

    /**
     * Facturation du cycle pour tous les abonnements en cours d'une offre (ou de toutes) : un échec n'arrête pas les autres.
     *
     * @return array{issued: int, already: int, failed: list<array{subscription_id: string, reason: string}>}
     */
    public function invoiceAll(Principal $principal, Perimeter $perimeter, ?string $planId, string $on): array
    {
        $result = ['issued' => 0, 'already' => 0, 'failed' => []];
        $visible = $this->children->sectionsByChild($perimeter);
        foreach ($this->catalog->activeOn($perimeter->tenantId, $perimeter->establishmentId, $on, $planId) as $row) {
            if (!isset($visible[(string) $row['learner_id']])) {
                continue;
            }
            try {
                $done = $this->invoice($principal, $perimeter, (string) $row['id'], $on);
                $done['already_invoiced'] === true ? ++$result['already'] : ++$result['issued'];
            } catch (HttpException $e) {
                $result['failed'][] = ['subscription_id' => (string) $row['id'], 'reason' => $e->getMessage()];
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> factures de service d'un abonnement */
    public function forSubscription(string $tenantId, string $establishmentId, string $subscriptionId): array
    {
        return array_map(self::resource(...), $this->store->select(self::TABLE, $tenantId, $establishmentId, ['subscription_id' => $subscriptionId], 'billing_period, id', 500));
    }

    /** @return array{0: string, 1: string, 2: string} code du cycle, premier et dernier jour */
    private function cycle(string $tenantId, string $establishmentId, string $billingCycle, string $on): array
    {
        if ($billingCycle === 'month') {
            $first = substr($on, 0, 7) . '-01';

            return [substr($on, 0, 7), $first, (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d')];
        }
        $kind = $billingCycle === 'term' ? 'trimester' : 'year';
        foreach ($this->periods->allAt($tenantId, $establishmentId, $on) as $period) {
            if ($period->kind === $kind) {
                $code = ($kind === 'year' ? 'A' : 'T' . $period->ordinal) . '-' . substr($period->startsOn, 0, 7);

                return [$code, $period->startsOn, $period->endsOn];
            }
        }

        throw HttpException::validation(['on' => ['Aucune période « ' . ($kind === 'year' ? 'année' : 'trimestre') . ' » de l’établissement ne couvre ce jour : créez le calendrier avant de facturer.']]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'subscription_id' => (string) $row['subscription_id'], 'cycle' => (string) $row['billing_period'],
            'invoice_id' => (string) $row['invoice_id'], 'amount_xaf' => (int) $row['amount_xaf'], 'created_at' => (string) $row['created_at'],
        ];
    }
}
