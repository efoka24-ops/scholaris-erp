<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary;

use Scholaris\Modules\Apps\PublicSchools\Policies\RoleMatrix;
use Scholaris\Modules\Apps\PublicSchools\PublicSchoolsApplication;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Application Primaire publique (clé `public_primary`) : évaluation par critères, passage, examens. Elle s'appuie sur les modules
 * communs `PublicSchools` (garde d'écriture, périmètre territorial, rôles) et n'atteint PublicSchools que par ses Contracts et
 * ses mécanismes de routage (Support\PublicRoutes, préfixe `/public-primary`). Tables `pp_`, paramètres `public_primary.*`.
 */
final class PublicPrimaryApplication
{
    public const KEY = 'public_primary';
    public const TARGET = 'primary';
    public const VERSION = '0.1.0';
    public const LABEL = 'Primaire publique';
    public const TABLE_PREFIX = 'pp_';
    public const SETTINGS_PREFIX = 'public_primary.';
    public const PERMISSION_PREFIX = 'public_primary.';
    public const CONTAINER_KEY = 'platform.applications.public_primary';
    public const ROUTE_PREFIX = '/public-primary';

    public const REQUIRED_MODULES = ['people', 'messaging', 'documents', 'attendance', 'assessment', 'staff', 'reporting', 'organisation'];

    /** Sous-modules prévus (vagues 2 et 3). */
    public const MODULES = ['Referential', 'Evaluation', 'Promotion', 'Exams', 'Documents', 'Sync'];

    private function __construct()
    {
    }

    /** Statut « bientôt » : la ligne de catalogue fait foi ; ouverture par PATCH /platform/applications/{key}. */
    public static function manifest(): ApplicationManifest
    {
        return new ApplicationManifest(
            key: self::KEY,
            label: self::LABEL,
            target: self::TARGET,
            status: ApplicationManifest::COMING_SOON,
            version: self::VERSION,
            requiredModules: self::REQUIRED_MODULES,
            monthlyPriceXaf: 30000,
            trialDays: 90,
            graceDays: 15,
        );
    }

    /** @return list<string> permissions `public_primary.*` portées par la matrice des rôles (triées) */
    public static function permissions(): array
    {
        return PublicSchoolsApplication::permissions(self::PERMISSION_PREFIX);
    }

    /** @return list<string> rôles qui portent au moins une permission `public_primary.*` */
    public static function roles(): array
    {
        $codes = [];
        foreach (RoleMatrix::ROLES as $code => $role) {
            foreach ($role['permissions'] as $permission) {
                if (str_starts_with($permission, self::PERMISSION_PREFIX)) {
                    $codes[] = $code;
                    break;
                }
            }
        }

        return $codes;
    }
}
