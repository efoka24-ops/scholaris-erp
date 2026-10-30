<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Console;

use Scholaris\Modules\Socle\Attendance\Application\AbsenceNotifier;

/** `attendance:retry-absences [client]` : rejoue les notifications d'absence restées en échec (à planifier toutes les heures). */
final class RetryAbsenceNotificationsCommand
{
    public function __construct(private readonly AbsenceNotifier $notifier)
    {
    }

    /** @param list<string> $args arguments après le nom de la commande */
    public function run(array $args): int
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                fwrite(STDERR, "Option inconnue : {$arg}\nUsage : artisan attendance:retry-absences [identifiant du client]\n");

                return 2;
            }
        }

        $result = $this->notifier->retryFailed($args[0] ?? null);
        echo "Notifications d'absence rejouées : {$result['retried']} ({$result['sent']} envoyée(s), {$result['still_failed']} encore en échec).\n";

        return 0;
    }
}
