<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\FeeReportAggregates;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\InspectionAggregates;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\InspectionClock;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\InspectionService;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Application\VisitBatchHandler;
use Scholaris\Modules\Apps\PublicSchools\Inspection\Http\InspectionController;

/**
 * Inspection (INS). Points d'extension : lot hors ligne `sync.handlers.public.inspection_visit`, indicateurs territoriaux
 * `public_schools.inspection_headcount` (O1) et `public_schools.fee_reports` (gratuité).
 */
return [
    InspectionClock::class => autowire(),
    InspectionService::class => autowire(),
    InspectionController::class => autowire(),
    VisitBatchHandler::class => autowire(),
    InspectionAggregates::class => autowire(),
    FeeReportAggregates::class => autowire(),
    'sync.handlers.public.inspection_visit' => autowire(VisitBatchHandler::class),
    'reporting.territory_aggregates.public_schools.inspection' => [InspectionAggregates::class, FeeReportAggregates::class],
];
