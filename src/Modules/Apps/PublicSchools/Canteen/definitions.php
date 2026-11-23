<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Canteen\Application\CanteenService;
use Scholaris\Modules\Apps\PublicSchools\Canteen\Application\HealthAggregates;
use Scholaris\Modules\Apps\PublicSchools\Canteen\Http\CanteenController;

/** Cantine et santé scolaire (CAN). Point d'extension : indicateur territorial `public_schools.health_campaigns`. */
return [
    CanteenService::class => autowire(),
    CanteenController::class => autowire(),
    HealthAggregates::class => autowire(),
    'reporting.territory_aggregates.public_schools.canteen' => [HealthAggregates::class],
];
