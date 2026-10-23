<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/**
 * Réédition « Rectificatif » d'un document valide (PP15) : un nouveau document est émis avec le MÊME numéro (révision + 1) et
 * la mention « Rectificatif », l'ancienne version est révoquée avec le motif et pointe vers la nouvelle (état « remplacé » à la
 * vérification). Avec le paramètre `documents.rectify_numbering` = « new_number » (SS12), le rectificatif prend un NOUVEAU numéro
 * et sa mention cite le numéro d'origine. Les gardes d'émission (IssuanceGuard) s'appliquent. Fourni par Documents ; exposé par
 * `POST /documents/{id}/rectify` (permission documents.rectify).
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
