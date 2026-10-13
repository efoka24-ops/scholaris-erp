<?php

declare(strict_types=1);

use function DI\get;

use Scholaris\Modules\Apps\Higher\Documents\Application\HigherDocumentService;
use Scholaris\Modules\Apps\Higher\Documents\Application\HigherIssuanceGuard;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Documents\Domain\CoreDocumentTypes;

/**
 * Liaisons du module DOC de l'IPES : contrat d'émission pour les autres modules Higher, garde d'émission de base (S18),
 * types DOC-01 à DOC-06. Chaque module Higher ajoute ses types sous `higher.document_types.<module>` et, s'il gère les filières,
 * sa langue d'enseignement sous `higher.documents.language_resolvers.<module>`.
 */
return [
    HigherDocumentIssuer::class => get(HigherDocumentService::class),
    \Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments::class => get(HigherDocumentService::class),
    'documents.issuance_guards.higher' => [HigherIssuanceGuard::class],
    'higher.document_types.documents' => [CoreDocumentTypes::class],
];
