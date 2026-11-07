<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Console;

use Scholaris\Modules\Socle\Documents\Application\DocumentJobService;

/**
 * `documents:process-jobs [--max-jobs=N] [--chunk=N]` : traite la file de génération en lot.
 * À planifier chaque minute (cron) ; sans tâche, la commande se termine aussitôt.
 */
final class ProcessDocumentJobsCommand
{
    public function __construct(private readonly DocumentJobService $jobs)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $maxJobs = 10;
        $chunk = null;
        foreach ($args as $arg) {
            if (preg_match('/^--max-jobs=(\d{1,4})$/', $arg, $m) === 1) {
                $maxJobs = max(1, (int) $m[1]);
            } elseif (preg_match('/^--chunk=(\d{1,4})$/', $arg, $m) === 1) {
                $chunk = max(1, (int) $m[1]);
            } else {
                fwrite(STDERR, "Usage : artisan documents:process-jobs [--max-jobs=N] [--chunk=N]\n");

                return 2;
            }
        }
        $stats = $this->jobs->work($maxJobs, $chunk);
        echo "Documents : {$stats['jobs']} tâche(s) traitée(s), {$stats['done']} terminée(s), {$stats['failed']} en échec, {$stats['chunks']} tranche(s).\n";

        return $stats['failed'] > 0 ? 1 : 0;
    }
}
