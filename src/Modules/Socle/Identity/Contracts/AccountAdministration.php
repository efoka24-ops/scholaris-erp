<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Gestion, par l'administrateur de plateforme, des comptes d'ADMINISTRATION seulement : super administrateurs d'un client
 * (rôle `super_admin`) et administrateurs de plateforme (rôle `platform_admin`, client technique `platform`).
 * Aucun autre compte d'un client n'est lisible ni modifiable par ce contrat (cloisonnement plateforme / client).
 */
interface AccountAdministration
{
    /**
     * Comptes détenant le rôle (super_admin ou platform_admin) dans le client.
     *
     * @return list<array{user_id: string, person_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, status: string, two_factor_enabled: bool, last_login_at: ?string, created_at: string}>
     */
    public function holders(string $tenantId, string $role): array;

    /**
     * Un compte détenteur du rôle, sinon 404.
     *
     * @return array{user_id: string, person_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, status: string, two_factor_enabled: bool, last_login_at: ?string, created_at: string}
     */
    public function holder(string $tenantId, string $role, string $userId): array;

    /**
     * Invite un administrateur de plateforme : compte en attente d'activation par code (aucun mot de passe transmis).
     *
     * @return array{person_id: string, user_id: string, login: string}
     */
    public function invitePlatformAdministrator(string $firstName, string $lastName, ?string $phone, ?string $email, string $locale, string $actorId): array;

    /** Bloque (locked, sessions révoquées) ou débloque (actif, ou en attente s'il n'a jamais été activé). */
    public function setBlocked(string $tenantId, string $role, string $userId, bool $blocked, string $actorId): void;

    /** Réinitialise l'accès : mot de passe et double authentification effacés, sessions révoquées, compte à réactiver par code. */
    public function resetAccess(string $tenantId, string $role, string $userId, string $actorId): void;
}
