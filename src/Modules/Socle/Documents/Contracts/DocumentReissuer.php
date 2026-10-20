<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * Réédition « Rectificatif » d'un document valide (PP15) : un nouveau document est émis avec le MÊME numéro (révision + 1) et
 * la mention « Rectificatif », l'ancienne version est révoquée avec le motif et pointe vers la nouvelle. Les gardes d'émission
 * (IssuanceGuard) s'appliquent. Fourni par Documents.
 *
 * Erreurs (HttpException) : 404 document introuvable dans l'établissement, 409 document déjà révoqué, 422 motif absent
 * (1 à 500 caractères) ou données invalides.
 */
interface DocumentReissuer
{
    /**
     * @param array<string, scalar|null> $data nouvelles valeurs ; vide = valeurs d'origine conservées
     */
    public function reissue(string $tenantId, string $establishmentId, string $documentId, array $data, string $reason, string $reissuedBy): IssuedDocument;
}
