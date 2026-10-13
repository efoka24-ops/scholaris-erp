<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Contracts;

/**
 * Émission d'un document de l'IPES pour les autres modules Higher (lettres d'admission, cartes, relevés…) : numéro RG-DOC-1 par
 * école, identité visuelle et langue de l'école ou de la filière (RG-ORG-4, RG-DOC-8), signataire du type, gardes d'émission
 * du socle (S18) et blocage pour impayé des seuls types bloquables (RG-DOC-7).
 */
interface HigherDocumentIssuer
{
    /**
     * @param array<string, scalar|null> $data variables propres au type
     * @return array<string, mixed> document émis (id, document_id, code, number, verification_token, locale, status…)
     * @throws \Scholaris\Core\Http\HttpException 422 type inconnu, profil d'école absent, variable non déclarée, garde ou blocage
     */
    public function issueFor(
        string $tenantId,
        string $establishmentId,
        string $code,
        string $personId,
        string $schoolId,
        ?string $programId,
        ?string $levelId,
        array $data,
        string $issuedBy,
        ?string $locale = null,
    ): array;
}
