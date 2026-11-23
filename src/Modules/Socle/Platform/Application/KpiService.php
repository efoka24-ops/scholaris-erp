<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use Scholaris\Modules\Socle\Audit\Contracts\ActivityJournal;
use Scholaris\Modules\Socle\Identity\Contracts\AccountActivity;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryMonitor;
use Scholaris\Modules\Socle\Organisation\Contracts\ClientProvisioning;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Scholaris\Modules\Socle\Platform\Domain\Calendar;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;

/**
 * Indicateurs d'usage du tableau de bord de la plateforme (C-112 point 2). Chaque chiffre vient d'une donnée enregistrée,
 * lue par les contrats des modules propriétaires (Identity, Audit, Messaging, Organisation, People) ; aucun n'est estimé.
 */
final class KpiService
{
    public const ONLINE_MINUTES = 15;
    public const SERIES_DAYS = 30;
    public const TOP = 10;

    public function __construct(
        private readonly AccountActivity $accounts,
        private readonly ActivityJournal $journal,
        private readonly DeliveryMonitor $deliveries,
        private readonly ClientProvisioning $clients,
        private readonly EnrollmentDirectory $enrollments,
        private readonly Catalog $catalog,
        private readonly PlatformDb $db,
        private readonly PlatformSettings $settings,
        private readonly PlatformClock $clock,
        private readonly HealthService $health,
        private readonly DirectMessages $direct,
    ) {
    }

    /**
     * @param bool $people liste nominative des connectés (pas pour la finance : « aucun utilisateur »)
     * @return array<string, mixed>
     */
    public function summary(bool $people): array
    {
        $now = $this->now();
        $today = $this->clock->today();
        $iso = static fn (DateTimeImmutable $d): string => $d->format(DATE_ATOM);
        $names = [];
        foreach ($this->clients->tenants() as $tenant) {
            $names[$tenant['id']] = $tenant['name'];
        }

        $online = $this->accounts->activeSessions($iso($now->modify('-' . self::ONLINE_MINUTES . ' minutes')));
        $series = $this->journal->dailyCounts('identity.login', Calendar::addDays($today, -(self::SERIES_DAYS - 1)));
        $days = [];
        for ($i = self::SERIES_DAYS - 1; $i >= 0; $i--) {
            $day = Calendar::addDays($today, -$i);
            $days[] = ['date' => $day, 'count' => $series[$day] ?? 0];
        }
        $sum = static fn (int $n): int => array_sum(array_column(array_slice($days, -$n), 'count'));

        return [
            'as_of' => $this->clock->iso(),
            'online' => [
                'window_minutes' => self::ONLINE_MINUTES,
                'total' => count($online),
                'items' => $people ? array_map(static fn (array $s): array => $s + ['tenant_name' => $names[$s['tenant_id']] ?? ($s['tenant_id'] === PlatformAudit::PLATFORM_TENANT ? 'Plateforme' : $s['tenant_id'])], array_slice($online, 0, 100)) : [],
            ],
            'logins' => ['today' => $sum(1), 'week' => $sum(7), 'month' => $sum(30), 'series' => $days],
            'active_users' => [
                'd7' => $this->accounts->activeUsersSince($iso($now->modify('-7 days'))),
                'd30' => $this->accounts->activeUsersSince($iso($now->modify('-30 days'))),
            ],
            'security' => [
                'failed_logins_24h' => $this->accounts->failedLoginsSince($iso($now->modify('-1 day'))),
                'lockouts_24h' => $this->journal->count('identity.login_locked', $iso($now->modify('-1 day'))),
                'locked_accounts' => $this->accounts->lockedAccounts(),
            ],
            'clients_activity' => $this->clientsActivity($names, $now),
            'usage_by_app' => $this->usageByApp($iso($now->modify('-7 days'))),
            'conversion' => $this->conversion(),
            'messages' => $this->messages($now, $today),
        ];
    }

    /**
     * @param array<string, string> $names
     * @return array<string, mixed>
     */
    private function clientsActivity(array $names, DateTimeImmutable $now): array
    {
        $days = $this->settings->value(PlatformSettings::INACTIVE_DAYS);
        $threshold = $now->modify('-' . $days . ' days')->format(DATE_ATOM);
        $last = $this->accounts->lastLoginByTenant();
        $actions = $this->journal->countsByTenant($now->modify('-7 days')->format(DATE_ATOM));
        $active = 0;
        $rows = [];
        foreach ($names as $id => $name) {
            $login = $last[$id] ?? null;
            $isActive = $login !== null && $login >= $threshold;
            $active += $isActive ? 1 : 0;
            $rows[] = ['tenant_id' => $id, 'name' => $name, 'actions_7d' => $actions[$id] ?? 0, 'last_login_at' => $login, 'active' => $isActive];
        }
        $most = $rows;
        usort($most, static fn (array $a, array $b): int => [$b['actions_7d'], $a['name']] <=> [$a['actions_7d'], $b['name']]);
        $least = $rows;
        usort($least, static fn (array $a, array $b): int => [$a['actions_7d'], $a['name']] <=> [$b['actions_7d'], $b['name']]);

        return [
            'inactive_days' => $days,
            'active' => $active,
            'inactive' => count($rows) - $active,
            'top_active' => array_slice($most, 0, self::TOP),
            'least_active' => array_slice($least, 0, self::TOP),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function usageByApp(string $since): array
    {
        $actions = $this->journal->countsByTenant($since);
        $out = [];
        foreach ($this->catalog->all() as $app) {
            $tenants = array_map(static fn (array $r): string => (string) $r['tenant_id'], $this->db->all('SELECT tenant_id FROM tenant_applications WHERE app_key = ? AND state <> ?', [(string) $app['key'], ApplicationAccess::CLOSED]));
            $establishments = 0;
            $learners = 0;
            $count = 0;
            foreach ($tenants as $tenantId) {
                foreach ($this->clients->establishmentsOf($tenantId) as $establishment) {
                    if ($establishment['type'] !== (string) $app['target'] || $establishment['closed_at'] !== null) {
                        continue;
                    }
                    $establishments++;
                    $learners += count($this->enrollments->personIdsInEstablishment($tenantId, $establishment['id']));
                }
                $count += $actions[$tenantId] ?? 0;
            }
            $out[] = ['key' => (string) $app['key'], 'label' => (string) $app['label'], 'clients' => count($tenants), 'establishments' => $establishments, 'learners' => $learners, 'actions_7d' => $count];
        }

        return $out;
    }

    /** @return array{trials: int, converted: int, rate_percent: ?int} */
    private function conversion(): array
    {
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM tenant_applications');
        $converted = (int) $this->db->scalar('SELECT COUNT(*) FROM tenant_applications WHERE paid_until IS NOT NULL OR state = ?', [ApplicationAccess::ACTIVE]);

        return ['trials' => $total, 'converted' => $converted, 'rate_percent' => $total === 0 ? null : (int) round(100 * $converted / $total)];
    }

    /** @return array<string, mixed> */
    private function messages(DateTimeImmutable $now, string $today): array
    {
        $dayStart = $today . 'T00:00:00';
        $monthStart = substr($today, 0, 7) . '-01T00:00:00';
        $period = function (string $since): array {
            $counts = $this->deliveries->countsSince($since);
            foreach ($this->direct->countsSince($since) as $channel => $statuses) {
                foreach ($statuses as $status => $n) {
                    $counts[$channel][$status] = ($counts[$channel][$status] ?? 0) + $n;
                }
            }
            $sms = $counts['sms'] ?? [];
            $email = $counts['email'] ?? [];

            return [
                'sms' => ['sent' => ($sms['sent'] ?? 0) + ($sms['delivered'] ?? 0), 'delivered' => $sms['delivered'] ?? 0, 'failed' => $sms['failed'] ?? 0],
                'email' => ['sent' => ($email['sent'] ?? 0) + ($email['delivered'] ?? 0), 'failed' => $email['failed'] ?? 0],
            ];
        };
        $state = $this->health->smsState();

        return ['today' => $period($dayStart), 'month' => $period($monthStart), 'sms_quota_remaining' => $state['quota_remaining'], 'sms_mode' => $state['mode']];
    }

    private function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->clock->iso()))->setTimezone(new DateTimeZone('Africa/Douala'));
    }
}
