<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Pickup\Console;

use Scholaris\Modules\Apps\Nursery\Pickup\Application\PickupWatch;

/** `nursery:pickup-watch` : veille de non-récupération, à lancer chaque minute par le cron (idempotente par niveau, enfant et jour). */
final class PickupWatchCommand
{
    public const NAME = 'nursery:pickup-watch';

    public function __construct(private readonly PickupWatch $watch)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $parent = 0;
        $director = 0;
        foreach ($this->watch->establishments() as [$tenantId, $establishmentId]) {
            $emitted = $this->watch->run($tenantId, $establishmentId);
            $parent += $emitted['parent'];
            $director += $emitted['director'];
        }
        echo sprintf("Veille de non-récupération : %d alerte(s) aux parents, %d à la direction.\n", $parent, $director);

        return 0;
    }
}
