<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Primary\Grades\Domain\Strategy\PrimaryRuleStrategy;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PrimaryCouncilLines;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PrimaryRuleScopes;

/**
 * Points d'extension d'Assessment : stratégie de calcul `primary` (S6), portées de règles classe -> niveau (S5) et préremplissage de
 * la conduite au conseil (PS4). Une clé de liste par application : elles s'ajoutent à celles des autres sans s'écraser.
 */
return [
    'assessment.rule_strategies.primary' => [PrimaryRuleStrategy::class],
    'assessment.rule_scopes.primary' => [PrimaryRuleScopes::class],
    'assessment.council_line_providers.primary' => [PrimaryCouncilLines::class],
];
