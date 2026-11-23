<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Console;

use Scholaris\Modules\Apps\Higher\Admissions\Application\DecisionService;

/**
 * `higher:reserve-expiry [AAAA-MM-JJ]` : les « admis sous réserve » dont la date de réserve est passée deviennent « refusé », avec
 * notification NOT-03 (RG-ADM-2). À planifier une fois par jour. Clé de conteneur `console.command.higher:reserve-expiry`.
 * Un client dont l'application est suspendue ou fermée est sauté (WriteGate).
 */
final class ReserveExpiryCommand
{
    public const NAME = 'higher:reserve-expiry';

    public function __construct(private readonly DecisionService $decisions)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : higher:reserve-expiry [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->decisions->expireConditionals($day);
        echo sprintf("Réserves expirées : %d candidature(s) refusée(s) ; %d client(s) sauté(s) (application suspendue ou fermée).\n", $result['expired'], $result['skipped_tenants']);

        return 0;
    }
}
