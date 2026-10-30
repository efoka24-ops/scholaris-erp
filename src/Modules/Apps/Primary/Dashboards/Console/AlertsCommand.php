<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Console;

use Scholaris\Modules\Apps\Primary\Dashboards\Application\AbsenceAlerts;

/**
 * `primary:alerts [AAAA-MM-JJ]` : alertes d'absences répétées des écoles primaires privées (T551). À planifier une fois par jour
 * (cron, après les appels de l'après-midi). Clé de conteneur `console.command.primary:alerts`. Un client suspendu ou fermé est sauté.
 */
final class AlertsCommand
{
    public const NAME = 'primary:alerts';

    public function __construct(private readonly AbsenceAlerts $alerts)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : primary:alerts [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->alerts->run($day);
        echo sprintf("Alertes d'absences : %d établissement(s) examiné(s), %d alerte(s) nouvelle(s) ; %d client(s) sauté(s).\n", $result['establishments'], $result['alerts'], $result['skipped_tenants']);

        return 0;
    }
}
