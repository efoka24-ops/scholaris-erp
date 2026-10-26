<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Territory\Application\BirthActAggregates;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\EnrolmentAggregates;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\SchoolPopulation;
use Scholaris\Modules\Apps\PublicSchools\Territory\Application\TeacherAggregates;
use Scholaris\Modules\Apps\PublicSchools\Territory\Http\TerritorySchoolsController;
use Scholaris\Modules\Apps\PublicSchools\Territory\Infrastructure\PdoOfficialSchools;

/**
 * Territoire : écoles d'un territoire (API-01) et fournisseurs d'agrégats territoriaux (T654, `reporting.territory_aggregates.public_schools`)
 * lus par `reporting:aggregate-territories` puis servis par GET /dashboards/territories/{territory} avec masquage des petites cases.
 */
return [
    PdoOfficialSchools::class => autowire(),
    TerritorySchoolsController::class => autowire(),
    SchoolPopulation::class => autowire(),
    EnrolmentAggregates::class => autowire(),
    BirthActAggregates::class => autowire(),
    TeacherAggregates::class => autowire(),
    'reporting.territory_aggregates.public_schools' => [EnrolmentAggregates::class, BirthActAggregates::class, TeacherAggregates::class],
];
