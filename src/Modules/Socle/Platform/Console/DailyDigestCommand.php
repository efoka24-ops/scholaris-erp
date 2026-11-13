<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Console;

use Scholaris\Modules\Socle\Identity\Contracts\OwnAccount;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Platform\Application\AccessRequestService;
use Scholaris\Modules\Socle\Platform\Application\HealthService;
use Scholaris\Modules\Socle\Platform\Application\KpiService;
use Scholaris\Modules\Socle\Platform\Application\PlatformAudit;
use Scholaris\Modules\Socle\Platform\Application\PlatformClock;
use Scholaris\Modules\Socle\Platform\Application\UserPreferences;
use Throwable;

/**
 * `platform:daily-digest` : résumé quotidien par e-mail aux membres de la plateforme qui l'ont demandé dans « Mon profil »
 * (santé, connexions, demandes d'accès en attente, SMS). À planifier une fois par jour (voir le rapport C-112).
 */
final class DailyDigestCommand
{
    public function __construct(
        private readonly UserPreferences $preferences,
        private readonly OwnAccount $accounts,
        private readonly DirectNotifier $notifier,
        private readonly HealthService $health,
        private readonly KpiService $kpi,
        private readonly AccessRequestService $requests,
        private readonly PlatformClock $clock,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args = []): int
    {
        $subscribers = $this->preferences->digestSubscribers();
        if ($subscribers === []) {
            echo "Résumé quotidien : aucun abonné.\n";

            return 0;
        }
        $health = $this->health->report();
        $kpi = $this->kpi->summary(false);
        $details = sprintf(
            'santé %s ; %d connexion(s) hier et aujourd\'hui, %d utilisateur(s) actif(s) sur 7 jours ; %d demande(s) d\'accès en attente ; %d SMS envoyé(s) aujourd\'hui (%d en échec).',
            $health['status'],
            $kpi['logins']['series'][28]['count'] + $kpi['logins']['today'],
            $kpi['active_users']['d7'],
            $this->requests->pendingCount(),
            $kpi['messages']['today']['sms']['sent'],
            $kpi['messages']['today']['sms']['failed'],
        );
        $sent = 0;
        foreach ($subscribers as $userId) {
            try {
                $profile = $this->accounts->profile(PlatformAudit::PLATFORM_TENANT, $userId);
                if ($profile['email'] === null) {
                    continue;
                }
                $result = $this->notifier->notify('platform_daily_digest', $profile['locale'], $profile['email'], null, ['first_name' => $profile['first_name'], 'date' => $this->clock->today(), 'details' => $details]);
                $sent += $result->status === DeliveryResult::SENT ? 1 : 0;
            } catch (Throwable) {
                continue;
            }
        }
        echo sprintf("Résumé quotidien : %d e-mail(s) envoyé(s) sur %d abonné(s).\n", $sent, count($subscribers));

        return 0;
    }
}
