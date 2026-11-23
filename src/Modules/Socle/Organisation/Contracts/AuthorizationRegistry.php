<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

use DateTimeInterface;

/**
 * Contrat d'ÉCRITURE « application » (D2) : registre des autorisations administratives (arrêté MINESUP…) d'une structure,
 * avec niveaux couverts, pièce numérisée et validité. Mêmes règles que la route /authorizations ; journalisé dans l'audit.
 * Erreurs : HttpException 422 (validation, référence en double = 409), 404 (identifiant inconnu dans l'établissement).
 */
interface AuthorizationRegistry
{
    /** @return string identifiant (ULID) de l'autorisation */
    public function register(string $tenantId, string $establishmentId, AuthorizationData $data, ?string $actorId = null): string;

    /** Remplace les valeurs de l'autorisation ; la structure ne change pas (une autre structure = une autre autorisation). */
    public function update(string $tenantId, string $establishmentId, string $authorizationId, AuthorizationData $data, ?string $actorId = null): void;

    public function remove(string $tenantId, string $establishmentId, string $authorizationId, ?string $actorId = null): void;

    public function find(string $tenantId, string $establishmentId, string $authorizationId): ?AuthorizationInfo;

    /** @return list<AuthorizationInfo> autorisations portées par la structure, triées par début de validité */
    public function forStructure(string $tenantId, string $establishmentId, string $structureId): array;

    /**
     * Vrai si une autorisation valide ce jour-là couvre la structure (elle-même ou l'une de ses ascendantes) et, quand elle
     * restreint les niveaux, le niveau donné (à défaut, le niveau déduit de la structure).
     */
    public function covers(string $tenantId, string $establishmentId, string $structureId, ?string $levelId, DateTimeInterface $on): bool;
}
