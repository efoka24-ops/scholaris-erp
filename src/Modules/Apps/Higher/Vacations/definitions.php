<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Teaching\Contracts\GradingCompleteness;
use Scholaris\Modules\Apps\Higher\Vacations\Application\VacationClock;
use Scholaris\Modules\Apps\Higher\Vacations\Documents\VacationDocumentTypes;
use Scholaris\Modules\Apps\Higher\Vacations\Infrastructure\GradeBookCompleteness;

/**
 * Liaisons du paiement des vacations (fusionnées par Higher/definitions.php) : fournisseur RG-ENS-5 (saisie des notes complète, lu par la
 * condition de payabilité IPES déclarée au socle), types DOC-25 et DOC-26.
 */
return [
    VacationClock::class => autowire(),
    GradingCompleteness::PREFIX . 'grades' => [get(GradeBookCompleteness::class)],
    'higher.document_types.vacations' => [VacationDocumentTypes::class],
];
