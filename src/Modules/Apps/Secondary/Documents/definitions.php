<?php

declare(strict_types=1);

use function DI\autowire;

use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentIssuance;
use Scholaris\Modules\Apps\Secondary\Documents\Application\DocumentTypeCatalogue;
use Scholaris\Modules\Apps\Secondary\Documents\Application\EntryDocumentTypes;
use Scholaris\Modules\Apps\Secondary\Documents\Application\StudentDocuments;
use Scholaris\Modules\Apps\Secondary\Documents\Application\TechnicalDocumentTypes;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\IssuedStudentDocuments;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFactsReader;

/**
 * Documents du secondaire : types E1 et E3 (clé de liste `secondary.document_types.*`), contrats d'émission, de lecture des documents
 * d'un élève et des faits d'élève (`secondary.student_facts.*`, matricule national fourni par Enrolment).
 */
return [
    'secondary.document_types.entry' => [EntryDocumentTypes::class],
    'secondary.document_types.technical' => [TechnicalDocumentTypes::class],
    SecondaryDocuments::class => autowire(DocumentIssuance::class),
    IssuedStudentDocuments::class => autowire(StudentDocuments::class),
    StudentFactsReader::class => autowire(DocumentTypeCatalogue::class),
];
