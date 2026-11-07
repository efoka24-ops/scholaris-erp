<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Secondary\Notifications\Application\FamilyNotifier;
use Scholaris\Modules\Apps\Secondary\Notifications\Contracts\FamilyNotifications;

/** Déclencheurs des notifications du secondaire, offerts aux autres sous-modules par contrat. */
return [
    FamilyNotifications::class => autowire(FamilyNotifier::class),
];
