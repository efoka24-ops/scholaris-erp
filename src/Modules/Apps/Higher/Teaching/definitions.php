<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Teaching\Application\HigherPayabilityCheck;
use Scholaris\Modules\Apps\Higher\Teaching\Application\TeachingClock;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\TeachingAssignments;
use Scholaris\Modules\Apps\Higher\Teaching\Documents\TeachingDocumentTypes;
use Scholaris\Modules\Apps\Higher\Teaching\Infrastructure\PdoTeachingAssignments;
use Scholaris\Modules\Socle\Staff\Contracts\VacationPayabilityCheck;

/**
 * Liaisons de l'enseignement (fusionnées par Higher/definitions.php) : affectations offertes aux autres sous-modules (Grades…),
 * conditions de payabilité RG-ENS-1/4/5 déclarées au socle (D16), types DOC-07, DOC-08, DOC-13.
 */
return [
    TeachingAssignments::class => get(PdoTeachingAssignments::class),
    TeachingClock::class => autowire(),
    VacationPayabilityCheck::REGISTRY_PREFIX . 'higher' => [get(HigherPayabilityCheck::class)],
    'higher.document_types.teaching' => [TeachingDocumentTypes::class],
];
