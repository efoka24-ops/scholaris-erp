<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Console;

use Scholaris\Modules\Apps\Higher\Academic\Application\ExpiryAlertService;

/**
 * `higher:expiry-alerts [AAAA-MM-JJ]` : alertes d'expiration 90/30/7 jours des autorisations MINESUP et des conventions de
 * tutelle (EX-ACA-07). À planifier une fois par jour. Clé de conteneur `console.command.higher:expiry-alerts`.
 */
final class ExpiryAlertsCommand
{
    public const NAME = 'higher:expiry-alerts';

    public function __construct(private readonly ExpiryAlertService $alerts)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : higher:expiry-alerts [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->alerts->run($day);
        echo sprintf(
            "Alertes d'expiration : %d alerte(s), %d message(s) ; %d client(s) sauté(s) (application suspendue ou fermée).\n",
            $result['alerts'],
            $result['messages'],
            $result['skipped_tenants'],
        );

        return 0;
    }
}
