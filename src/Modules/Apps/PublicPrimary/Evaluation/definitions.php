<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\PublicPrimary\Contracts\AnnualMentions;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ContextResolver;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\CriterionChecksHandler;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\EvaluationService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\MentionAggregates;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\MentionReader;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\RemediationService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ReportCardService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ReportCardsSection;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application\ScoreService;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Http\EvaluationController;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Http\ReportCardController;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoReportCards;

/**
 * Évaluation de la Primaire publique : saisie, mentions, remédiation, bulletins. Points d'extension : lots hors ligne
 * (`sync.handlers.public_primary.criterion_checks`), indicateur territorial (`reporting.territory_aggregates.public_primary.evaluation`),
 * section du portail famille (`portals.sections.public_primary`).
 */
return [
    PdoEvaluation::class => autowire(),
    PdoReportCards::class => autowire(),
    ContextResolver::class => autowire(),
    EvaluationService::class => autowire(),
    RemediationService::class => autowire(),
    ScoreService::class => autowire(),
    MentionReader::class => autowire(),
    AnnualMentions::class => get(MentionReader::class),
    ReportCardService::class => autowire(),
    CriterionChecksHandler::class => autowire(),
    MentionAggregates::class => autowire(),
    ReportCardsSection::class => autowire(),
    EvaluationController::class => autowire(),
    ReportCardController::class => autowire(),
    'sync.handlers.public_primary.criterion_checks' => get(CriterionChecksHandler::class),
    'reporting.territory_aggregates.public_primary.evaluation' => [MentionAggregates::class],
    'portals.sections.public_primary' => [ReportCardsSection::class],
];
