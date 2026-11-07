<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * Émission de documents officiels à partir d'un modèle : numéro unique et continu, QR, registre.
 * Les applications déclarent leurs modèles (type, HTML FR/EN, variables autorisées). Fourni par Documents (S06).
 */
interface DocumentIssuer
{
    /**
     * @param array<string, scalar|null> $data valeurs des variables du modèle
     * @throws \Scholaris\Core\Http\HttpException 422 si le type est inconnu ou une variable non déclarée
     */
    public function issue(
        string $tenantId,
        string $establishmentId,
        string $type,
        string $subjectPersonId,
        array $data,
        string $issuedBy,
        string $locale = 'fr',
    ): IssuedDocument;

    /** Révoque (jamais de suppression) ; un motif est obligatoire. */
    public function revoke(string $tenantId, string $documentId, string $reason, string $revokedBy): void;
}
