<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * Émission avec options (code d'école du numéro, école émettrice, signataire, blocage pour impayé). Les gardes enregistrées
 * (IssuanceGuard) s'appliquent comme pour `issue`. Fourni par Documents.
 */
interface ExtendedDocumentIssuer extends DocumentIssuer
{
    /** @param array<string, scalar|null> $data */
    public function issueWithOptions(
        string $tenantId,
        string $establishmentId,
        string $type,
        string $subjectPersonId,
        array $data,
        string $issuedBy,
        string $locale,
        IssueOptions $options,
    ): IssuedDocument;
}
