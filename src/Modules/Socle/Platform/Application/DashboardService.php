<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Tableau de bord de l'administrateur de plateforme. Chaque indicateur vient d'une donnée enregistrée ; un indicateur
 * sans source est rendu `available: false`, jamais estimé.
 */
final class DashboardService
{
    public const TRIAL_HORIZON_DAYS = 14;

    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly ClientProvisioning $clients,
        private readonly AccessRequestService $requests,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionPayments $payments,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = $this->clock->today();
        $horizon = Calendar::addDays($today, self::TRIAL_HORIZON_DAYS);

        $byApp = [];
        foreach ($this->catalog->all() as $app) {
            $byApp[(string) $app['key']] = ['key' => $app['key'], 'label' => $app['label'], 'status' => $app['status'], 'target' => $app['target'], 'states' => []];
        }
        foreach ($this->db->all('SELECT app_key, state, COUNT(*) AS total FROM tenant_applications GROUP BY app_key, state') as $row) {
            if (isset($byApp[(string) $row['app_key']])) {
                $byApp[(string) $row['app_key']]['states'][(string) $row['state']] = (int) $row['total'];
            }
        }
        $open = 0;
        foreach ($byApp as $key => $app) {
            $byApp[$key]['open'] = array_sum(array_diff_key($app['states'], [ApplicationAccess::CLOSED => 0]));
            $open += $byApp[$key]['open'];
        }

        $trialsEnding = $this->db->all(
            'SELECT * FROM tenant_applications WHERE state = ? AND trial_ends_on >= ? AND trial_ends_on <= ? ORDER BY trial_ends_on, tenant_id',
            [ApplicationAccess::TRIAL, $today, $horizon],
        );
        $unpaid = $this->db->all('SELECT * FROM tenant_applications WHERE state IN (?, ?) ORDER BY tenant_id, app_key', [ApplicationAccess::PAST_DUE, ApplicationAccess::SUSPENDED]);

        $mrr = 0;
        $paying = 0;
        foreach ($this->db->all('SELECT * FROM tenant_applications WHERE state = ?', [ApplicationAccess::ACTIVE]) as $row) {
            $mrr += $this->entitlements->totalOf($row);
            $paying++;
        }

        return [
            'as_of' => $this->clock->iso(),
            'clients' => ['available' => true, 'total' => count($this->clients->tenants())],
            'access_requests_pending' => ['available' => true, 'total' => $this->requests->pendingCount()],
            'applications' => ['available' => true, 'open_total' => $open, 'items' => array_values($byApp)],
            'trials_ending' => [
                'available' => true,
                'horizon_days' => self::TRIAL_HORIZON_DAYS,
                'total' => count($trialsEnding),
                'items' => array_map(fn (array $row): array => $this->entitlements->present($row) + ['tenant_name' => $this->entitlements->tenantName((string) $row['tenant_id'])], array_slice($trialsEnding, 0, 50)),
            ],
            'unpaid' => [
                'available' => true,
                'total' => count($unpaid),
                'items' => array_map(fn (array $row): array => $this->entitlements->present($row) + ['tenant_name' => $this->entitlements->tenantName((string) $row['tenant_id'])], array_slice($unpaid, 0, 50)),
            ],
            // Revenu mensuel récurrent : somme des tarifs effectifs des abonnements payés et en cours (hors essais).
            'mrr_xaf' => ['available' => true, 'value' => $mrr, 'paying_subscriptions' => $paying],
            'collected_last_30_days_xaf' => ['available' => true, 'value' => $this->payments->collectedSince(Calendar::addDays($today, -30))],
            'health' => ['available' => false, 'reason' => 'Aucune sonde de santé n\'alimente encore le tableau de bord de la plateforme.'],
        ];
    }
}
