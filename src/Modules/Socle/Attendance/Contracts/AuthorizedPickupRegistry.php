<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Contracts;

/**
 * Registre des personnes autorisées à récupérer un enfant : source unique `authorized_pickups`, aussi lue et écrite par les
 * routes `/authorized-pickups` du socle. Toute requête est bornée au client et à l'établissement.
 *
 * RG-SOR-1 : une remise désigne une autorisation par son IDENTIFIANT (`find`, `validOn`), jamais par un nom libre ; le contrat
 * n'offre volontairement aucune recherche par nom. Le contrôle de validité est `AuthorizedPickupInfo::isValidOn`.
 *
 * Erreurs de `grant` et `revoke` (HttpException) : 404 enfant ou autorisation introuvable dans l'établissement, 422 données invalides.
 */
interface AuthorizedPickupRegistry
{
    /**
     * Autorisations valables ce jour-là (permanentes et du jour, actives) pour ces enfants.
     *
     * @param list<string> $learnerIds
     * @return list<AuthorizedPickupInfo> triées par enfant puis par ordre de création
     */
    public function validOn(string $tenantId, string $establishmentId, array $learnerIds, string $date): array;

    /** Une autorisation de l'établissement, quel que soit son statut (révoquée comprise), ou null. */
    public function find(string $tenantId, string $establishmentId, string $id): ?AuthorizedPickupInfo;

    /**
     * Crée une autorisation active et rend son identifiant.
     *
     * @param array{name?: mixed, relationship?: mixed, phone?: mixed, photo_ref?: mixed, grant_type?: mixed, valid_on?: mixed} $data
     *        `grant_type` = `permanent` (sans `valid_on`) ou `day` (avec `valid_on` AAAA-MM-JJ) ; `photo_ref` = référence opaque (80 caractères au plus)
     */
    public function grant(string $tenantId, string $establishmentId, string $learnerId, array $data, string $actorId): string;

    /** Révoque l'autorisation (idempotent). */
    public function revoke(string $tenantId, string $establishmentId, string $id, string $actorId): void;
}
