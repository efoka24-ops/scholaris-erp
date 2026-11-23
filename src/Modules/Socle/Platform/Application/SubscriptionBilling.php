<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use PDOException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Domain\Regularisation;
use Scholaris\Modules\Socle\Platform\Domain\Subscription;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Passage quotidien des abonnements (`platform:billing-run [AAAA-MM-JJ]`), idempotent : relances J-14, J-3 et J0
 * (une par palier et par échéance), passage en impayé le lendemain de l'échéance, suspension après le délai de grâce.
 */
final class SubscriptionBilling
{
    public function __construct(
        private readonly PlatformDb $db,
        private readonly Catalog $catalog,
        private readonly EntitlementService $entitlements,
        private readonly PlatformNotifier $notifier,
        private readonly PlatformAudit $audit,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @return array{date: string, reminders_sent: int, reminders_undelivered: int, past_due: int, suspended: int} */
    public function run(?string $today = null): array
    {
        $today ??= $this->clock->today();
        if (!Calendar::isDate($today)) {
            throw new \InvalidArgumentException('Date attendue au format AAAA-MM-JJ.');
        }
        $result = ['date' => $today, 'reminders_sent' => 0, 'reminders_undelivered' => 0, 'past_due' => 0, 'suspended' => 0];
        $rows = $this->db->all('SELECT * FROM tenant_applications WHERE state IN (?, ?, ?) ORDER BY tenant_id, app_key', [
            ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE,
        ]);

        foreach ($rows as $row) {
            $app = $this->catalog->find((string) $row['app_key']);
            if ($app === null) {
                continue;
            }
            $tenantId = (string) $row['tenant_id'];
            $dueOn = Subscription::dueOn($row['paid_until'] === null ? null : (string) $row['paid_until'], (string) $row['trial_ends_on']);
            $variables = [
                'label' => (string) $app['label'],
                'establishment_name' => $this->entitlements->tenantName($tenantId),
                'due_on' => Calendar::display($dueOn),
                'amount' => $this->entitlements->totalOf($row),
            ];

            $reminder = $this->remind($row, $today, $dueOn, $variables);
            if ($reminder !== null) {
                $result[$reminder === DeliveryResult::BLOCKED ? 'reminders_undelivered' : 'reminders_sent']++;
            }

            $state = (string) $row['state'];
            $grace = $this->entitlements->graceDaysOf($row);
            $next = Subscription::nextState($state, $today, $dueOn, $grace, $row['promise_until'] === null ? null : (string) $row['promise_until']);
            if ($next === $state) {
                continue;
            }
            $now = $this->clock->iso();
            $values = ['state' => $next, 'updated_at' => $now];
            if ($state !== ApplicationAccess::PAST_DUE) {
                $values['past_due_since'] = Calendar::addDays($dueOn, 1);
            }
            $level = null;
            if ($next === ApplicationAccess::SUSPENDED) {
                // Blocage automatique : mode régularisation de paiement, au niveau choisi (client, sinon réglage de la plateforme).
                $level = $this->entitlements->lockLevelOf($row);
                $values += [
                    'suspended_at' => $now, 'state_reason' => 'unpaid', 'regularisation' => $level,
                    'regularisation_origin' => Regularisation::ORIGIN_AUTO, 'regularisation_since' => $today, 'promise_until' => null, 'promise_reason' => null,
                ];
            }
            $changed = $this->db->update('tenant_applications', $values, ['tenant_id' => $tenantId, 'id' => (string) $row['id'], 'state' => $state]);
            if ($changed === 0) {
                continue;
            }
            $this->audit->record($tenantId, null, 'platform.subscription_' . $next, 'tenant_application', (string) $row['id'], ['app_key' => $row['app_key'], 'due_on' => $dueOn, 'run_date' => $today] + ($level === null ? [] : ['regularisation_level' => $level, 'before' => ['state' => $state], 'after' => ['state' => $next]]));
            if ($next === ApplicationAccess::SUSPENDED) {
                $result['suspended']++;
                $this->notifier->toClient($tenantId, $level === Regularisation::LOCKED ? 'platform_regularisation_locked' : 'platform_subscription_suspended', $variables);
            } else {
                $result['past_due']++;
                $this->notifier->toClient($tenantId, 'platform_subscription_past_due', $variables + [
                    'date' => Calendar::display(Calendar::addDays($dueOn, $grace)),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, scalar> $variables
     * @return string|null statut de livraison, null si aucune relance n'est due
     */
    private function remind(array $row, string $today, string $dueOn, array $variables): ?string
    {
        $daysLeft = Calendar::daysBetween($today, $dueOn);
        $sent = array_map('intval', array_column($this->db->all(
            'SELECT stage FROM platform_reminders WHERE tenant_id = ? AND tenant_application_id = ? AND due_on = ?',
            [(string) $row['tenant_id'], (string) $row['id'], $dueOn],
        ), 'stage'));
        $reminder = Subscription::reminderFor($daysLeft, $sent);
        if ($reminder === null) {
            return null;
        }

        $now = $this->clock->iso();
        foreach (array_diff($reminder['covered'], $sent) as $stage) {
            try {
                $this->db->insert('platform_reminders', [
                    'id' => (string) new Ulid(),
                    'tenant_id' => (string) $row['tenant_id'],
                    'tenant_application_id' => (string) $row['id'],
                    'due_on' => $dueOn,
                    'stage' => $stage,
                    'outcome' => $stage === $reminder['stage'] ? 'pending' : 'skipped',
                    'created_at' => $now,
                ]);
            } catch (PDOException) {
                return null;
            }
        }

        $status = $this->notifier->toClient((string) $row['tenant_id'], 'platform_subscription_reminder', $variables + ['offset' => $daysLeft]);
        $this->db->update('platform_reminders', ['outcome' => $status], [
            'tenant_id' => (string) $row['tenant_id'], 'tenant_application_id' => (string) $row['id'], 'due_on' => $dueOn, 'stage' => $reminder['stage'],
        ]);

        return $status;
    }
}
