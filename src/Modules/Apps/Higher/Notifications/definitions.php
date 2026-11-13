<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Notifications\Application\Notifier;
use Scholaris\Modules\Apps\Higher\Notifications\Application\ReminderCommand;
use Scholaris\Modules\Apps\Higher\Notifications\Contracts\HigherNotifier;

/** Liaisons des notifications de l'IPES : contrat d'envoi pour les autres modules Higher, tâche planifiée des relances. */
return [
    HigherNotifier::class => get(Notifier::class),
    Notifier::class => autowire(),
    'console.command.higher:reminders' => autowire(ReminderCommand::class),
];
