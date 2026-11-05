<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Domain;

/**
 * Permissions fines du back-office (`platform.*`) et rôles de la plateforme (C-112). La table role_permissions reste la
 * source de vérité (migrations 202610089080, 202610151700, 202610201000, 202611060101) ; cette liste sert à rendre au front
 * les permissions de la personne connectée (GET /platform/me) et aux tests de la matrice.
 */
final class PlatformPermissions
{
    public const ADMIN = 'platform_admin';
    public const SUPPORT = 'platform_support';
    public const FINANCE = 'platform_finance';
    public const AUDITOR = 'platform_auditor';
    public const ROLES = [self::ADMIN, self::SUPPORT, self::FINANCE, self::AUDITOR];

    public const ALL = [
        'platform.access',
        'platform.dashboard.read',
        'platform.kpi.finance',
        'platform.health.read',
        'platform.clients.read',
        'platform.clients.manage',
        'platform.catalog.read',
        'platform.catalog.manage',
        'platform.requests.read',
        'platform.requests.decide',
        'platform.applications.manage',
        'platform.pricing.manage',
        'platform.payments.read',
        'platform.payments.manage',
        'platform.regularisation.manage',
        'platform.admins.read',
        'platform.admins.manage',
        'platform.users.read',
        'platform.users.logout',
        'platform.users.access_code',
        'platform.audit.read',
        'platform.audit.export',
        'platform.messages.read',
        'platform.messages.retry',
        'platform.messages.send',
        'platform.messages.broadcast',
        'platform.settings.read',
        'platform.settings.manage',
    ];

    private function __construct()
    {
    }
}
