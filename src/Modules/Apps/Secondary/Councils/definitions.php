<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Councils\Application\ConductRulesService;
use Scholaris\Modules\Apps\Secondary\Councils\Application\SecondaryCouncilLines;
use Scholaris\Modules\Apps\Secondary\Councils\Application\SecondaryReportCardVariables;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\PromotionPlans;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;
use Scholaris\Modules\Apps\Secondary\Councils\Infrastructure\SecondaryRuleScopes;
use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application\OrientationDocumentTypes;
use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application\SecondaryPromotionPlanner;

/**
 * Points d'extension d'Assessment pour le secondaire : stratégie de calcul `secondary` (T812, S6), portées de règles classe puis
 * niveau (T900, S5), pré-remplissage des lignes du conseil (T902, SS6) et variables du bulletin (T904, SS5). Une clé de liste par
 * application : elle s'ajoute à celles des autres sans les écraser. Contrat ConductRules (barème de conduite et sanction proposée,
 * en lecture) pour Discipline (T865).
 */
return [
    'assessment.rule_strategies.secondary' => [SecondaryStrategy::class],
    'assessment.rule_scopes.secondary' => [SecondaryRuleScopes::class],
    'assessment.council_line_providers.secondary' => [SecondaryCouncilLines::class],
    'assessment.report_card_variables.secondary' => [SecondaryReportCardVariables::class],
    ConductRules::class => get(ConductRulesService::class),
    'secondary.document_types.orientation' => [OrientationDocumentTypes::class],
    SecondaryPromotionPlanner::CONTAINER_KEY => [SecondaryPromotionPlanner::class],
    PromotionPlans::class => get(SecondaryPromotionPlanner::class),
];
