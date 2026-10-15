<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\Deliberation\Application\HigherDeliberationGuard;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\SignedMinutesIssuanceGuard;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherBtsHndStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Contracts\DeliberationRecords;
use Scholaris\Modules\Apps\Higher\Deliberation\Documents\DeliberationDocumentTypes;
use Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure\HigherRuleScopes;
use Scholaris\Modules\Apps\Higher\Deliberation\Infrastructure\PdoDeliberationRecords;

/**
 * Liaisons de la délibération (fusionnées par Higher/definitions.php) : stratégies de calcul LMD et BTS/HND (S6), règles par
 * filière (S5), garde de délibération (S8), garde d'émission « PV signé » (RG-DOC-3, S18), documents DOC-09/10/14/15/16 et
 * lecture des délibérations pour les autres sous-modules. Les sources de notes se déclarent sous
 * `higher.deliberation.mark_sources.<module>` (Contracts\MarkSource).
 */
return [
    DeliberationRecords::class => autowire(PdoDeliberationRecords::class),
    'assessment.rule_strategies.higher_deliberation' => [HigherLmdStrategy::class, HigherBtsHndStrategy::class],
    'assessment.rule_scopes.higher_deliberation' => [HigherRuleScopes::class],
    'assessment.deliberation_guards.higher_deliberation' => [HigherDeliberationGuard::class],
    'documents.issuance_guards.higher_deliberation' => [SignedMinutesIssuanceGuard::class],
    'higher.document_types.deliberation' => [DeliberationDocumentTypes::class],
];
