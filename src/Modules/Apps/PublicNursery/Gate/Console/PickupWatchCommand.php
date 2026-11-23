<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Gate\Console;

use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Support\NurseryWriteGate;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;

/** `public-nursery:pickup-watch` : veille de non-récupération (NOT-06), chaque minute par le cron ; idempotente ; inactive si l'application est fermée ou suspendue. */
final class PickupWatchCommand
{
    public const NAME = 'public-nursery:pickup-watch';

    public function __construct(private readonly GateService $gate, private readonly NurseryWriteGate $access, private readonly PublicNurseryClock $clock)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $parent = 0;
        $director = 0;
        foreach ($this->gate->establishments() as [$tenantId, $establishmentId]) {
            if (!$this->access->allowsWrites($tenantId)) {
                continue;
            }
            $sent = $this->gate->watch($tenantId, $establishmentId, $this->clock->at());
            $parent += $sent['parent'];
            $director += $sent['director'];
        }
        echo sprintf("Veille de non-récupération : %d alerte(s) aux parents, %d à la direction.\n", $parent, $director);

        return 0;
    }
}
