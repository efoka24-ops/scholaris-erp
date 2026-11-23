<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\PublicPrimary\Promotion\Application\EndOfYearCensusSection;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Application\PromotionAggregates;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Application\PromotionService;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Http\PromotionController;
use Scholaris\Modules\Apps\PublicPrimary\Promotion\Infrastructure\PdoPromotion;

/**
 * Passage de la Primaire publique. Points d'extension : indicateur territorial (`reporting.territory_aggregates.public_primary.promotion`)
 * et section de fin d'année du questionnaire statistique (`public_schools.census_sections.public_primary`).
 */
return [
    PdoPromotion::class => autowire(),
    PromotionService::class => autowire(),
    PromotionAggregates::class => autowire(),
    EndOfYearCensusSection::class => autowire(),
    PromotionController::class => autowire(),
    'reporting.territory_aggregates.public_primary.promotion' => [PromotionAggregates::class],
    'public_schools.census_sections.public_primary' => [EndOfYearCensusSection::class],
];
