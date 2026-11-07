<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Membres de la plateforme (client technique `platform`) et leur rôle : `platform_admin`, `platform_support`,
 * `platform_finance` ou `platform_auditor` (C-112). Un membre détient UN rôle de plateforme ; en changer remplace
 * l'affectation (ancienne close, nouvelle ouverte), journalisé. Aucun compte d'un client n'est concerné.
 */
interface PlatformStaff
{
    public const ROLES = ['platform_admin', 'platform_support', 'platform_finance', 'platform_auditor'];

    /**
     * @return list<array{user_id: string, person_id: string, first_name: string, last_name: string, phone: ?string, email: ?string, locale: string, status: string, two_factor_enabled: bool, last_login_at: ?string, created_at: string, role: string}>
     */
    public function members(): array;

    /** Rôle de plateforme en vigueur du compte, null s'il n'est pas membre. */
    public function roleOf(string $userId): ?string;

    /**
     * Remplace le rôle de plateforme du membre. 404 compte inconnu ou non membre, 422 rôle inconnu. Les règles métier
     * (soi-même, dernier administrateur) appartiennent à l'appelant.
     */
    public function assign(string $userId, string $role, string $actorId): void;
}
