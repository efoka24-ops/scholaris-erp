<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Services\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Nursery\Sections\Application\ChildDirectory;
use Scholaris\Modules\Apps\Nursery\Support\Access;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;
use Scholaris\Modules\Apps\Nursery\Support\Paging;
use Scholaris\Modules\Apps\Nursery\Support\Perimeter;
use Scholaris\Modules\Apps\Nursery\Support\Store;

/**
 * Services de l'école (EX-MAT-29, MQ-22) : garderie, cantine et transport. Une offre désigne un frais du catalogue du socle (le
 * montant n'est jamais ici) et un cycle de facturation (mois, trimestre, année) ; un enfant s'y abonne à partir d'une date et s'en
 * désabonne à une date. Deux abonnements actifs du même enfant à la même offre sont refusés.
 */
final class ServiceCatalog
{
    public const PLANS = 'nu_service_plans';
    public const SUBSCRIPTIONS = 'nu_service_subscriptions';
    public const KINDS = ['daycare', 'canteen', 'transport'];
    public const CYCLES = ['month', 'term', 'year'];

    public function __construct(
        private readonly Store $store,
        private readonly ChildDirectory $children,
        private readonly NurseryClock $clock,
        private readonly Access $access,
    ) {
    }

    /**
     * @param array<string, mixed> $data kind, label, fee_item_id, billing_cycle
     * @return array<string, mixed>
     */
    public function createPlan(Principal $principal, string $establishmentId, array $data): array
    {
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::PLANS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'kind' => (string) $data['kind'], 'label' => (string) $data['label'], 'fee_item_id' => (string) $data['fee_item_id'],
            'billing_cycle' => (string) $data['billing_cycle'], 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.service.plan_created', 'service_plan', $id, null, ['kind' => $data['kind'], 'fee_item_id' => $data['fee_item_id']]);

        return self::planResource($this->plan($principal->tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $changes label?, fee_item_id?, active?
     * @return array<string, mixed>
     */
    public function updatePlan(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $before = $this->plan($principal->tenantId, $establishmentId, $id);
        $values = array_intersect_key($changes, array_flip(['label', 'fee_item_id']));
        if (array_key_exists('active', $changes)) {
            $values['active'] = $changes['active'] === true ? 1 : 0;
        }
        $this->store->update(self::PLANS, $principal->tenantId, $establishmentId, $values + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.service.plan_updated', 'service_plan', $id, ['label' => $before['label'], 'fee_item_id' => $before['fee_item_id']], $changes);

        return self::planResource($this->plan($principal->tenantId, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function plans(string $tenantId, string $establishmentId, ?string $kind = null): array
    {
        return array_map(self::planResource(...), $this->store->select(self::PLANS, $tenantId, $establishmentId, $kind !== null ? ['kind' => $kind] : [], 'kind, label, id', 500));
    }

    /** @return array<string, mixed> 404 si l'offre n'est pas de cet établissement */
    public function plan(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::PLANS, $tenantId, $establishmentId, $id, 'Offre de service introuvable.');
    }

    /**
     * @param array<string, mixed> $data learner_id, plan_id, starts_on?
     * @return array<string, mixed>
     */
    public function subscribe(Principal $principal, Perimeter $perimeter, array $data): array
    {
        $tenantId = $perimeter->tenantId;
        $establishmentId = $perimeter->establishmentId;
        $learnerId = (string) $data['learner_id'];
        $section = $this->children->assertInPerimeter($perimeter, $learnerId);
        $this->access->assertWritable($principal, $perimeter, $section, 'child', $learnerId);
        $plan = $this->plan($tenantId, $establishmentId, (string) $data['plan_id']);
        if ((int) $plan['active'] !== 1) {
            throw HttpException::conflict('Cette offre n’est plus proposée.');
        }
        if ($this->store->count(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['learner_id' => $learnerId, 'plan_id' => (string) $plan['id'], 'status' => 'active']) > 0) {
            throw HttpException::conflict('L’enfant est déjà inscrit à ce service.');
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::SUBSCRIPTIONS, $tenantId, $establishmentId, [
            'id' => $id, 'learner_id' => $learnerId, 'plan_id' => (string) $plan['id'], 'starts_on' => (string) ($data['starts_on'] ?? $this->clock->today()),
            'ends_on' => null, 'status' => 'active', 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'nursery.service.subscribed', 'service_subscription', $id, null, ['learner_id' => $learnerId, 'plan_id' => $plan['id']]);

        return $this->subscriptionResource($this->subscription($tenantId, $establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function end(Principal $principal, Perimeter $perimeter, string $id, string $endsOn): array
    {
        $row = $this->subscription($perimeter->tenantId, $perimeter->establishmentId, $id);
        $section = $this->children->assertInPerimeter($perimeter, (string) $row['learner_id']);
        $this->access->assertWritable($principal, $perimeter, $section, 'service_subscription', $id);
        if ((string) $row['status'] !== 'active') {
            throw HttpException::conflict('Abonnement déjà terminé.');
        }
        if ($endsOn < (string) $row['starts_on']) {
            throw HttpException::validation(['ends_on' => ['La fin précède le début de l’abonnement.']]);
        }
        $this->store->update(self::SUBSCRIPTIONS, $perimeter->tenantId, $perimeter->establishmentId, ['ends_on' => $endsOn, 'status' => 'ended', 'updated_at' => $this->clock->now()], ['id' => $id]);
        $this->access->audit($principal, 'nursery.service.ended', 'service_subscription', $id, null, ['ends_on' => $endsOn]);

        return $this->subscriptionResource($this->subscription($perimeter->tenantId, $perimeter->establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function subscription(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::SUBSCRIPTIONS, $tenantId, $establishmentId, $id, 'Abonnement introuvable.');
    }

    /**
     * Abonnements visibles (périmètre de sections), filtres facultatifs.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function page(Perimeter $perimeter, Paging $paging, ?string $planId, ?string $learnerId, ?string $status): array
    {
        $visible = $this->children->sectionsByChild($perimeter);
        $where = array_filter(['plan_id' => $planId, 'learner_id' => $learnerId, 'status' => $status], static fn (?string $v): bool => $v !== null);
        $rows = [];
        foreach ($this->store->select(self::SUBSCRIPTIONS, $perimeter->tenantId, $perimeter->establishmentId, $where, 'starts_on DESC, id', 5000) as $row) {
            if (isset($visible[(string) $row['learner_id']])) {
                $rows[] = $this->subscriptionResource($row);
            }
        }

        return $paging->slice($rows);
    }

    /**
     * Abonnements en cours à ce jour (une offre, ou toutes).
     *
     * @return list<array<string, mixed>>
     */
    public function activeOn(string $tenantId, string $establishmentId, string $day, ?string $planId = null): array
    {
        $out = [];
        foreach ($this->store->select(self::SUBSCRIPTIONS, $tenantId, $establishmentId, $planId !== null ? ['plan_id' => $planId] : [], 'learner_id, id', 10000) as $row) {
            if ((string) $row['starts_on'] <= $day && ($row['ends_on'] === null || (string) $row['ends_on'] >= $day)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Services d'un enfant pour son parent (offre, dates, état).
     *
     * @return list<array<string, mixed>>
     */
    public function forLearner(string $tenantId, string $establishmentId, string $learnerId): array
    {
        return array_map($this->subscriptionResource(...), $this->store->select(self::SUBSCRIPTIONS, $tenantId, $establishmentId, ['learner_id' => $learnerId], 'starts_on DESC, id', 200));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function subscriptionResource(array $row): array
    {
        $plan = $this->store->find(self::PLANS, (string) $row['tenant_id'], (string) $row['establishment_id'], (string) $row['plan_id']);

        return [
            'id' => (string) $row['id'], 'learner_id' => (string) $row['learner_id'], 'plan_id' => (string) $row['plan_id'],
            'plan_label' => $plan !== null ? (string) $plan['label'] : null, 'kind' => $plan !== null ? (string) $plan['kind'] : null,
            'billing_cycle' => $plan !== null ? (string) $plan['billing_cycle'] : null, 'starts_on' => (string) $row['starts_on'],
            'ends_on' => $row['ends_on'] !== null ? (string) $row['ends_on'] : null, 'status' => (string) $row['status'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function planResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'kind' => (string) $row['kind'], 'label' => (string) $row['label'], 'fee_item_id' => (string) $row['fee_item_id'],
            'billing_cycle' => (string) $row['billing_cycle'], 'active' => (int) $row['active'] === 1,
        ];
    }
}
