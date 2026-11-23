<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Activité des comptes de TOUS les clients pour le back-office de la plateforme (C-112) : sessions ouvertes, connexions,
 * comptes verrouillés, fiche d'un utilisateur ; seule écriture : forcer la déconnexion d'un compte. Aucune donnée métier,
 * jamais de secret (mot de passe, code, jeton). Les téléphones et e-mails sont rendus en clair : l'appelant les masque.
 */
interface AccountActivity
{
    /**
     * Sessions actives (non révoquées, non expirées, utilisées depuis `$since`), la plus récemment utilisée d'abord.
     *
     * @return list<array{tenant_id: string, user_id: string, first_name: string, last_name: string, role: ?string, since: string, last_seen_at: string, device: ?string}>
     */
    public function activeSessions(string $since, int $limit = 200): array;

    /** Comptes des clients (hors plateforme) connectés depuis `$since` (dernière connexion). */
    public function activeUsersSince(string $since): int;

    /**
     * Dernière connexion d'un compte de chaque client (hors plateforme), null si aucune.
     *
     * @return array<string, ?string>
     */
    public function lastLoginByTenant(): array;

    /** Identifiants dont la connexion a échoué depuis `$since` (compteurs anti-force brute en cours). */
    public function failedLoginsSince(string $since): int;

    /** Comptes actuellement verrouillés (bloqués ou « Ce n'est pas moi »), hors plateforme. */
    public function lockedAccounts(): int;

    /**
     * Fiche d'un compte d'un client vue par la plateforme, avec ses sessions ouvertes. 404 inconnu.
     *
     * @return array{user_id: string, person_id: string, tenant_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, status: string, roles: list<string>, two_factor_enabled: bool, last_login_at: ?string, created_at: string, sessions: list<array{id: string, device: ?string, created_at: string, last_used_at: ?string, expires_at: string}>}
     */
    public function account(string $tenantId, string $userId): array;

    /** Révoque toutes les sessions ouvertes du compte (déconnexion forcée), journalisé. @return int sessions révoquées */
    public function revokeSessions(string $tenantId, string $userId, string $actorId): int;
}
