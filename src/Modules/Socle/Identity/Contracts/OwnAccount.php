<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * « Mon profil » (C-112 point 6) : le compte connecté, pour tout utilisateur (plateforme comprise). Lecture de son identité
 * et de ses sessions, modification de ses nom, téléphone, e-mail et langue, déconnexion de ses autres appareils.
 * Le mot de passe et la double authentification restent sur leurs routes d'Identity (/auth/change-password, /auth/2fa/*).
 */
interface OwnAccount
{
    /**
     * @return array{user_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, two_factor_enabled: bool, last_login_at: ?string}
     */
    public function profile(string $tenantId, string $userId): array;

    /**
     * Modifie les champs fournis (clés absentes : inchangées). Téléphone et e-mail déjà normalisés par l'appelant ; l'un des
     * deux reste toujours renseigné (identifiant de connexion). 409 déjà utilisé dans ce client, 422 aucun identifiant.
     *
     * @param array{first_name?: string, last_name?: string, phone?: ?string, email?: ?string, locale?: string} $changes
     * @return array{user_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, two_factor_enabled: bool, last_login_at: ?string}
     */
    public function update(string $tenantId, string $userId, array $changes): array;

    /**
     * Sessions ouvertes du compte ; `current` marque celle de la requête.
     *
     * @return list<array{id: string, device: ?string, created_at: string, last_used_at: ?string, expires_at: string, current: bool}>
     */
    public function sessions(string $tenantId, string $userId): array;

    /** Révoque toutes les sessions du compte sauf celle de la requête (journalisé). @return int sessions révoquées */
    public function revokeOtherSessions(string $tenantId, string $userId): int;
}
