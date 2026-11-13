<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Internships\Application\InternshipClock;
use Scholaris\Modules\Apps\Higher\Internships\Contracts\DefenseResults;
use Scholaris\Modules\Apps\Higher\Internships\Documents\InternshipDocumentTypes;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\DefenseMarkSource;
use Scholaris\Modules\Apps\Higher\Internships\Infrastructure\PdoDefenseResults;

/**
 * Liaisons des stages (fusionnées par Higher/definitions.php) : documents DOC-20 à 23, horloge, lecture des notes de soutenance
 * pour les autres sous-modules (Contracts, DefenseResults), note de soutenance offerte à la délibération (DefenseMarkSource).
 */
return [
    InternshipClock::class => autowire(),
    DefenseMarkSource::class => autowire(),
    'higher.deliberation.mark_sources.internships' => [DefenseMarkSource::class],
    DefenseResults::class => get(PdoDefenseResults::class),
    PdoDefenseResults::class => autowire(),
    'higher.document_types.internships' => [InternshipDocumentTypes::class],
];
