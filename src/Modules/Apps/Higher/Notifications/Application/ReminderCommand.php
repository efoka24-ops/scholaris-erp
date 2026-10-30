<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Notifications\Application;

/**
 * Commande planifiée `php bin/console higher:reminders [AAAA-MM-JJ]` (une fois par jour) : relances NOT-08 et NOT-09 de tous
 * les établissements IPES dont l'application n'est pas suspendue. Déclarée sous `console.command.higher:reminders`.
 */
final class ReminderCommand
{
    public function __construct(private readonly ReminderRunner $runner)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $date = $args[0] ?? null;
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            fwrite(STDERR, "Date attendue au format AAAA-MM-JJ.\n");

            return 1;
        }
        $sent = $this->runner->runAll($date);
        fwrite(STDOUT, sprintf("Relances IPES envoyées : %d\n", $sent));

        return 0;
    }
}
