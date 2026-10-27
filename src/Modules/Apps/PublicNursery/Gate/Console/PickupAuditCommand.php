<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Gate\Console;

use Scholaris\Modules\Apps\PublicNursery\Gate\Application\GateService;
use Scholaris\Modules\Apps\PublicNursery\Support\PublicNurseryClock;

/** `public-nursery:pickup-audit [AAAA-MM-JJ]` : contrôle quotidien O7 (SC-1) ; code 1 si une remise sans autorisation valide est trouvée. */
final class PickupAuditCommand
{
    public const NAME = 'public-nursery:pickup-audit';

    public function __construct(private readonly GateService $gate, private readonly PublicNurseryClock $clock)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? $this->clock->today();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            fwrite(STDERR, "Usage : public-nursery:pickup-audit [AAAA-MM-JJ]\n");

            return 2;
        }
        $total = 0;
        foreach ($this->gate->establishments() as [$tenantId, $establishmentId]) {
            $total += count($this->gate->audit($tenantId, $establishmentId, $day));
        }
        echo sprintf("Contrôle O7 du %s : %d remise(s) sans autorisation valide.\n", $day, $total);

        return $total === 0 ? 0 : 1;
    }
}
