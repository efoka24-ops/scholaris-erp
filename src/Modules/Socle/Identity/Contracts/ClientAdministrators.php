<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Amorce le premier administrateur d'un nouveau client (rôle `super_admin`, niveau client). Le compte reste « en attente » :
 * la personne l'active par code reçu (flux d'activation d'Identity). Utilisé à l'acceptation d'une demande d'accès.
 */
interface ClientAdministrators
{
    /**
     * @return array{person_id: string, user_id: string, login: string} `login` : identifiant de connexion (téléphone ou e-mail)
     * @throws \Scholaris\Core\Http\HttpException 422 si invalide, 409 si le téléphone ou l'e-mail est déjà utilisé dans ce client
     */
    public function invite(string $tenantId, string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $grantedBy): array;

    /**
     * Rôles de direction d'une application donnés aux administrateurs du client (affectation `super_admin` de niveau client en
     * vigueur), établissement par établissement, à ceux qui n'y exercent encore AUCUN rôle de l'application (rôle autre que
     * super_admin portant une permission `<préfixe>*` hors `<préfixe>access`). Idempotent ; rôle inconnu, réservé (super_admin, rôles
     * de plateforme) ou établissement hors du client ignorés. Rôle de niveau client : une affectation de niveau client.
     *
     * @param list<string> $roles codes des rôles, dans l'ordre d'attribution
     * @param string $permissionPrefix préfixe des permissions de l'application (ex. `higher.`)
     * @param list<string> $establishmentIds établissements du client desservis par l'application
     * @return list<array{person_id: string, role: string, establishment_id: string|null}> affectations créées
     */
    public function grantApplicationRoles(string $tenantId, array $roles, string $permissionPrefix, array $establishmentIds, string $grantedBy): array;
}
