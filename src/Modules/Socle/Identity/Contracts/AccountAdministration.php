<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Gestion, par l'administrateur de plateforme, des comptes d'ADMINISTRATION seulement : super administrateurs d'un client
 * (rôle `super_admin`) et administrateurs de plateforme (rôle `platform_admin`, client technique `platform`).
 * Aucun autre compte d'un client n'est modifiable par ce contrat (cloisonnement plateforme / client), sauf la création
 * d'un code d'accès (`issueAccessCode`) ; la liste `users` est en lecture seule et sans donnée métier.
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

    /**
     * Exception ADDITIVE au cloisonnement, en lecture seule : comptes d'un client (identité, contact, statut, rôles), sans
     * aucune donnée métier, pour que la plateforme dépanne l'accès d'un utilisateur. Jamais le client technique `platform`.
     *
     * @return list<array{user_id: string, person_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, status: string, roles: list<string>, two_factor_enabled: bool, last_login_at: ?string}>
     */
    public function users(string $tenantId): array;

    /**
     * Crée un code d'accès à usage unique pour un compte du client (activation s'il est en attente, réinitialisation
     * s'il est actif ; refus 409 s'il est bloqué). Même mécanisme que /auth/activation : le code se saisit tel quel sur
     * l'écran d'activation ou de mot de passe oublié. Invalide les codes ouverts du compte ; 5 par heure et par compte (429).
     * Le code en clair n'est rendu qu'ici, une seule fois, jamais stocké ni journalisé ; l'envoi éventuel appartient à
     * l'appelant. Compte introuvable dans ce client : 404 ; son propre compte : 409.
     *
     * @return array{code: string, purpose: string, expires_at: string, expires_in: int, login: string}
     */
    public function issueAccessCode(string $tenantId, string $userId, string $actorId): array;

    /** Vrai si `code` est le code d'accès ouvert et non expiré du compte (renvoi du même code sans le régénérer). */
    public function isOpenAccessCode(string $tenantId, string $userId, string $code): bool;
}
