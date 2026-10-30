<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Console;

use Scholaris\Modules\Apps\Nursery\Pickup\Application\ConsistencyCheck;
use Scholaris\Modules\Apps\Nursery\Support\NurseryClock;

/**
 * `nursery:consistency-check [AAAA-MM-JJ]` : contrôle quotidien SC-MAT-1. Toute remise sans autorisation valable est listée et la
 * commande se termine en erreur (code 1), de sorte que le cron signale l'anomalie.
 */
final class ConsistencyCheckCommand
{
    public const NAME = 'nursery:consistency-check';

    public function __construct(private readonly ConsistencyCheck $check, private readonly NurseryClock $clock)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? $this->clock->today();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            fwrite(STDERR, "Usage : nursery:consistency-check [AAAA-MM-JJ]\n");

            return 2;
        }
        $count = 0;
        foreach ($this->check->establishments() as [$tenantId, $establishmentId]) {
            foreach ($this->check->violations($tenantId, $establishmentId, $day) as $violation) {
                ++$count;
                echo sprintf("- %s / %s : %s (événement %s)\n", $tenantId, $establishmentId, $violation['problem'], $violation['event_id']);
            }
        }
        echo sprintf("Contrôle de cohérence du %s : %d anomalie(s).\n", $day, $count);

        return $count === 0 ? 0 : 1;
    }
}
