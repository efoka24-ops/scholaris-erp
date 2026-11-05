<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Secondary\Councils\Application\ConductRulesService;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;

/**
 * Points d'extension d'Assessment pour le secondaire : stratégie de calcul `secondary` (T812, S6). Une clé de liste par
 * application : elle s'ajoute aux stratégies des autres sans les écraser. Contrat ConductRules (barème de conduite et sanction
 * proposée, en lecture) pour Discipline (T865).
 */
return [
    'assessment.rule_strategies.secondary' => [SecondaryStrategy::class],
    ConductRules::class => get(ConductRulesService::class),
];
