<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery\Development\Console;

use Scholaris\Modules\Apps\Nursery\Development\Application\MediaRetention;

/**
 * `nursery:purge-media [AAAA-MM-JJ]` : purge des photos du cahier de vie arrivées à échéance de conservation, pour chaque école.
 * À planifier une fois par jour (cron). Idempotente : une photo purgée ne l'est qu'une fois.
 */
final class PurgeMediaCommand
{
    public const NAME = 'nursery:purge-media';

    public function __construct(private readonly MediaRetention $retention)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = null;
        foreach ($args as $arg) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg) !== 1) {
                fwrite(STDERR, "Usage : nursery:purge-media [AAAA-MM-JJ]\n");

                return 2;
            }
            $day = $arg;
        }
        $total = 0;
        foreach ($this->retention->establishments() as [$tenantId, $establishmentId]) {
            $total += $this->retention->purge($tenantId, $establishmentId, $day)['purged'];
        }
        echo sprintf("Purge des photos du cahier de vie : %d photo(s) supprimée(s).\n", $total);

        return 0;
    }
}
