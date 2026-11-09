<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Fees\Console;

use Scholaris\Modules\Apps\Secondary\Fees\Application\FeeNotices;

/**
 * `secondary:fee-notices [AAAA-MM-JJ]` : relances SMS NOT-07, NOT-08 et NOT-10 du secondaire (FeeNotices). À planifier une fois par
 * jour. Clé de conteneur `console.command.secondary:fee-notices`. Un client suspendu ou fermé est sauté.
 */
final class FeeNoticesCommand
{
    public const NAME = 'secondary:fee-notices';

    public function __construct(private readonly FeeNotices $notices)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : secondary:fee-notices [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->notices->run($day);
        echo sprintf(
            "Relances du secondaire : %d établissement(s), NOT-07 %d, NOT-08 %d, NOT-10 %d ; %d établissement(s) gardent les relances du socle ; %d client(s) sauté(s).\n",
            $result['establishments'],
            $result['not07'],
            $result['not08'],
            $result['not10'],
            $result['skipped_socle_reminders'],
            $result['skipped_tenants'],
        );

        return 0;
    }
}
