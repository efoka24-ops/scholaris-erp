<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Console;

use Scholaris\Modules\Socle\Messaging\Application\MessageService;
use Scholaris\Modules\Socle\Messaging\Application\SendQueue;

/** `queue:send [--limit=N]` : reprend les envois en échec (backoff) et traite les livraisons dues. À planifier chaque minute. */
final class SendQueueCommand
{
    public function __construct(private readonly SendQueue $queue)
    {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        $limit = MessageService::BATCH;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d{1,4})$/', $arg, $m) === 1 && (int) $m[1] > 0) {
                $limit = (int) $m[1];
                continue;
            }
            fwrite(STDERR, "Option inconnue ou invalide : {$arg}\nUsage : artisan queue:send [--limit=N]\n");

            return 2;
        }

        $result = $this->queue->run($limit);
        echo "File d'envoi : {$result['processed']} livraison(s) traitée(s), {$result['retried']} reprise(s), {$result['abandoned']} abandon(s).\n";

        return 0;
    }
}
