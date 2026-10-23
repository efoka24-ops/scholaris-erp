<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Secondary\Setup\Application\ProfileResolver;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\EstablishmentProfiles;

/** Liaisons du paramétrage : contrat de profil offert aux autres sous-modules Secondary (et à la garde de module). */
return [
    EstablishmentProfiles::class => autowire(ProfileResolver::class),
];
