<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Console;

use Scholaris\Modules\Socle\Reporting\Application\ReportingClock;
use Scholaris\Modules\Socle\Reporting\Application\TerritoryAggregator;

/**
 * `reporting:aggregate-territories [AAAA-MM-JJ] [client]` : recalcule les agrégats par territoire (PP4) à la date indiquée
 * (aujourd'hui par défaut), pour un client ou tous les clients non fermés. À planifier une fois par nuit ; idempotent.
 * Clé de conteneur `console.command.reporting:aggregate-territories`.
 */
final class AggregateTerritoriesCommand
{
    public const NAME = 'reporting:aggregate-territories';

    public function __construct(
        private readonly TerritoryAggregator $aggregator,
        private readonly ReportingClock $clock,
    ) {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        $date = null;
        $tenant = null;
        foreach ($args as $arg) {
            if ($date === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) === 1) {
                $date = $arg;
            } elseif ($tenant === null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/', $arg) === 1) {
                $tenant = $arg;
            } else {
                fwrite(STDERR, "Argument inconnu : {$arg}\nUsage : " . self::NAME . " [AAAA-MM-JJ] [identifiant du client]\n");

                return 2;
            }
        }

        $results = $this->aggregator->run($date ?? $this->clock->today(), $tenant);
        echo sprintf("Agrégats territoriaux : %d client(s).\n", count($results));
        foreach ($results as $tenantId => $rows) {
            echo sprintf("  client %s : %d case(s)\n", $tenantId, $rows);
        }

        return 0;
    }
}
