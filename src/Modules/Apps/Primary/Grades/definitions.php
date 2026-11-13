<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Primary\Grades\Contracts\SheetProgress;
use Scholaris\Modules\Apps\Primary\Grades\Domain\Strategy\PrimaryRuleStrategy;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PdoSheetProgress;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PrimaryCouncilLines;
use Scholaris\Modules\Apps\Primary\Grades\Infrastructure\PrimaryRuleScopes;

/**
 * Points d'extension d'Assessment : stratégie de calcul `primary` (S6), portées de règles classe -> niveau (S5) et préremplissage de
 * la conduite au conseil (PS4). Une clé de liste par application : elles s'ajoutent à celles des autres sans s'écraser.
 * Contrat offert aux autres sous-modules Primary : avancement des feuilles de compétences (tableau de bord, clôture).
 */
return [
    'assessment.rule_strategies.primary' => [PrimaryRuleStrategy::class],
    'assessment.rule_scopes.primary' => [PrimaryRuleScopes::class],
    'assessment.council_line_providers.primary' => [PrimaryCouncilLines::class],
    SheetProgress::class => autowire(PdoSheetProgress::class),
];
