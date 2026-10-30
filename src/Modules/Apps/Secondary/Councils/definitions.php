<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;

/**
 * Points d'extension d'Assessment pour le secondaire : stratégie de calcul `secondary` (T812, S6). Une clé de liste par
 * application : elle s'ajoute aux stratégies des autres sans les écraser.
 */
return [
    'assessment.rule_strategies.secondary' => [SecondaryStrategy::class],
];
