<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Attribution de rôles pour le compte d'un acteur (import en masse des acteurs d'un établissement). Applique EXACTEMENT les règles
 * de POST /role-assignments : l'acteur ne peut attribuer que les rôles qu'il peut déjà attribuer (permission
 * `identity.roles.assign` dans l'établissement, rôle de niveau client ou `super_admin` réservés), jamais `platform_admin`. Aucun
 * droit supplémentaire n'est créé ici.
 */
interface RoleAssignmentGateway
{
    /**
     * Rôles que l'acteur peut attribuer dans cet établissement (liste vide s'il n'a pas le droit d'attribuer des rôles).
     *
     * @return list<array{code: string, level: string, sensitive: bool, label_fr: string, label_en: string}>
     */
    public function assignableRoles(string $tenantId, string $actorUserId, string $establishmentId): array;

    /**
     * Identifiants de connexion déjà pris dans le client (comptes existants, quel que soit leur état). Les valeurs doivent être
     * normalisées : téléphone « +237… », e-mail en minuscules.
     *
     * @param list<string> $phones
     * @param list<string> $emails
     * @return array{phones: list<string>, emails: list<string>} sous-ensemble des valeurs reçues qui sont déjà utilisées
     */
    public function identifiersInUse(string $tenantId, array $phones, array $emails): array;

    /**
     * Attribue le rôle à une personne du client, comme POST /role-assignments (contrôle de droit, périmètre, dates, audit).
     * `scopeType` : tenant, establishment, org_unit, structure, class… ; `scopeId` : identifiant du périmètre ; dates ISO 8601.
     *
     * @return string identifiant de l'affectation créée
     * @throws \Scholaris\Core\Http\HttpException 403 droit insuffisant, 422 données invalides
     */
    public function assign(string $tenantId, string $actorUserId, string $personId, string $role, string $scopeType, ?string $scopeId, ?string $establishmentId, ?string $startsAt, ?string $endsAt): string;
}
