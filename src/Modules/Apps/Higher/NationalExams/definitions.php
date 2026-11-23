<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Higher\NationalExams\Application\NationalExamsClock;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\MinesupExportTypes;
use Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure\NationalExamDocumentTypes;

/**
 * Liaisons des examens nationaux (fusionnées par Higher/definitions.php) : horloge, documents DOC-12 et DOC-17, types d'export
 * de la liste MINESUP (S14, colonnes paramétrables, gabarit exact à confirmer).
 */
return [
    NationalExamsClock::class => autowire(),
    'higher.document_types.national_exams' => [NationalExamDocumentTypes::class],
    'integrations.export_types.higher_nationalexams' => MinesupExportTypes::types(),
];
