<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\MorningPackageService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\PickupCodeService;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Application\PickupSyncHandler;
use Scholaris\Modules\Apps\PublicNursery\Pickup\Http\PickupCodeController;

/**
 * Sécurité de la sortie de la Maternelle publique (C-106) : codes à usage unique (T724), paquet du matin et gestionnaire de lots hors
 * ligne (T725) branché sur le point d'extension UNIQUE du socle Sync (`sync.handlers.<kind>`), sans route ni file de synchronisation propre.
 */
return [
    PickupCodeService::class => autowire(),
    MorningPackageService::class => autowire(),
    PickupCodeController::class => autowire(),
    PickupSyncHandler::class => autowire(),
    'sync.handlers.' . PickupSyncHandler::KIND => \DI\get(PickupSyncHandler::class),
];
