<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Console;

use Scholaris\Modules\Socle\Messaging\Application\SmsStatusSync;

/** `sms:sync-status [--limit=N]` : met à jour sent/delivered/failed des SMS suivis par le fournisseur. À planifier toutes les 15 minutes. */
final class SyncSmsStatusCommand
{
    public function __construct(private readonly SmsStatusSync $sync)
    {
    }

    /** @param list<string> $args */
    public function run(array $args = []): int
    {
        $limit = 200;
        foreach ($args as $arg) {
            if (preg_match('/^--limit=(\d{1,4})$/', $arg, $m) === 1 && (int) $m[1] > 0) {
                $limit = (int) $m[1];
                continue;
            }
            fwrite(STDERR, "Option inconnue ou invalide : {$arg}\nUsage : artisan sms:sync-status [--limit=N]\n");

            return 2;
        }

        $result = $this->sync->run($limit);
        echo "Suivi des SMS : {$result['checked']} contrôlé(s), {$result['delivered']} remis, {$result['failed']} en échec, {$result['pending']} en attente, {$result['errors']} erreur(s) du fournisseur.\n";

        return $result['errors'] > 0 && $result['errors'] === $result['checked'] ? 1 : 0;
    }
}
