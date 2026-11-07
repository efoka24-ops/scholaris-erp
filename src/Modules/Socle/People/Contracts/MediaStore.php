<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Dépôt de médias des applications (photos, pièces numérisées), construit au-dessus d'AttachmentStore : le type réel (PDF, JPG,
 * PNG), l'analyse FileScanner et le stockage privé sont ceux d'AttachmentStore, rien n'est réimplémenté ici. Ajouts propres à
 * ce contrat : espace de noms imposé, taille maximale réduite (`people.media.max_bytes`, 2 Mo par défaut, jamais plus que le
 * plafond d'AttachmentStore), référence opaque, lecture par URL signée à durée limitée, suppression.
 *
 * Le média est rangé sous le domaine `media-<espace>` : une application ne peut donc écrire ni dans le dossier des personnes
 * (`people`) ni dans le domaine d'une autre application. L'espace et le propriétaire viennent du code de l'application,
 * jamais d'une requête client ; ils sont tout de même validés.
 */
interface MediaStore
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Durée maximale d'une URL signée, en secondes (borne technique). */
    public const MAX_TTL = 3600;

    /**
     * @param string $namespace espace de noms de l'application (`public-nursery`…) : minuscules, chiffres et tirets, 2 à 20 caractères
     * @param string $ownerId objet propriétaire (établissement, section…) : lettres, chiffres, `_` et `-`, 26 caractères au plus
     *
     * @throws \InvalidArgumentException média refusé (vide, trop lourd, type non accepté, contenu actif, espace ou propriétaire invalide) ; message lisible
     */
    public function put(string $tenantId, string $namespace, string $ownerId, string $content): MediaRef;

    /**
     * URL à durée limitée (`$ttl` secondes, entre 1 et MAX_TTL) : `<route>?tenant=…&ref=…&expires=…&signature=…`. La route est celle
     * de l'application qui sert le média ; elle relit ces quatre paramètres et appelle `readSigned`. L'existence du média n'est pas
     * contrôlée ici : un média absent ou d'un autre client donne un refus 404 à la lecture.
     *
     * @param string $route chemin absolu de l'application (`/api/v1/…`), sans requête ni fragment
     *
     * @throws \InvalidArgumentException chemin invalide
     */
    public function signedUrl(string $tenantId, MediaRef $ref, int $ttl, string $route): string;

    /**
     * Lecture par lien signé : la signature et l'expiration sont vérifiées AVANT toute lecture du stockage.
     *
     * @throws \Scholaris\Core\Http\HttpException 403 lien falsifié ou expiré, 404 média introuvable (supprimé ou d'un autre client)
     */
    public function readSigned(string $tenantId, MediaRef $ref, int $expires, string $signature): MediaContent;

    /** Supprime le média du client ; sans effet s'il n'existe pas ou s'il appartient à un autre client. */
    public function delete(string $tenantId, MediaRef $ref): void;
}
