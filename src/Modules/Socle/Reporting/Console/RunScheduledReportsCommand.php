<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Console;

use Scholaris\Modules\Socle\Reporting\Application\ScheduledReportService;

/**
 * `reports:run [AAAA-MM-JJ] [client]` : exécute les rapports planifiés échus (à planifier une fois par jour).
 * Idempotent : une échéance déjà jouée n'envoie rien de plus.
 */
final class RunScheduledReportsCommand
{
    public function __construct(private readonly ScheduledReportService $reports)
    {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        $date = null;
        $tenant = null;
        foreach ($args as $arg) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) === 1 && $date === null) {
                $date = $arg;
            } elseif (!str_starts_with($arg, '--') && $tenant === null) {
                $tenant = $arg;
            } else {
                fwrite(STDERR, "Argument inconnu : {$arg}\nUsage : artisan reports:run [AAAA-MM-JJ] [identifiant du client]\n");

                return 2;
            }
        }

        $results = $this->reports->runDue($tenant, $date);
        echo sprintf("Rapports planifiés : %d exécuté(s).\n", count($results));
        foreach ($results as $result) {
            echo sprintf(
                "  client %s, rapport %s : %s (%d envoyé(s), %d en échec)\n",
                $result['tenant_id'],
                $result['schedule_id'],
                $result['status'],
                $result['sent_count'],
                $result['failed_count'],
            );
        }

        return 0;
    }
}
