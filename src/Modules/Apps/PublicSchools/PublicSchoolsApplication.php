<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools;

use Scholaris\Modules\Apps\PublicSchools\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Modules communs des écoles publiques (clé de plateforme `public_schools`, non vendue seule : requise par `public_primary`
 * et `public_nursery`). Manifeste lu par le catalogue (clé de conteneur `platform.applications.public_schools`), rôles R01-R21
 * et permissions `public_schools.*`.
 *
 * Conventions imposées à tous les sous-modules (vérifiées par tests/Architecture/PublicSchoolsConventionsTest) :
 *  - tables préfixées `pub_`, avec `tenant_id` obligatoire (et `establishment_id` pour les tables d'école), ids ULID CHAR(26) ;
 *  - paramètres sous les clés `public_schools.*` ; seuls les `Contracts/` du socle sont utilisés ;
 *  - les autres applications (PublicPrimary, PublicNursery) n'atteignent PublicSchools que par `PublicSchools/Contracts` ;
 *  - toute route est enregistrée par Support\PublicRoutes (écritures derrière WriteGate) ;
 *  - aucun seuil ni règle officielle en dur dans `Domain/` : tout est paramètre ;
 *  - aucune colonne `apee` hors du sous-module `Apee` (RG-ELV-1 : aucun lien entre inscription et contribution).
 */
final class PublicSchoolsApplication
{
    public const KEY = 'public_schools';
    public const TARGET = 'primary';
    public const VERSION = '0.1.0';
    public const LABEL = 'Écoles publiques - module commun';
    public const TABLE_PREFIX = 'pub_';
    public const SETTINGS_PREFIX = 'public_schools.';
    public const PERMISSION_PREFIX = 'public_schools.';
    public const CONTAINER_KEY = 'platform.applications.public_schools';
    public const ROUTE_PREFIX = '/public';

    /** Modules du socle requis (même liste que la ligne de catalogue amorcée par 202610192200_platform_catalog_public_apps.sql). */
    public const REQUIRED_MODULES = ['people', 'messaging', 'documents', 'attendance', 'staff', 'reporting', 'organisation'];

    /** Sous-modules prévus (vagues 1 à 3) ; chacun aura son dossier, son routes.php et son definitions.php. */
    public const MODULES = [
        'Schools', 'Pupils', 'Attendance', 'Staff', 'Messages', 'Apee', 'Census', 'Inspection', 'Canteen', 'Territory', 'Sync', 'Documents',
    ];

    private function __construct()
    {
    }

    /** Statut « bientôt » : la ligne de catalogue en base fait foi, l'application ne s'ouvre que par PATCH /platform/applications/{key}. */
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

    /** @return list<string> codes des rôles des écoles publiques (R01-R21 sauf R11, R12 et R14, propres à la Maternelle) */
    public static function roles(): array
    {
        return array_keys(RoleMatrix::ROLES);
    }

    /**
     * @param string $prefix `public_schools.` (défaut) ou `public_primary.` : permissions déclarées par la matrice pour ce préfixe
     * @return list<string> triées
     */
    public static function permissions(string $prefix = self::PERMISSION_PREFIX): array
    {
        $permissions = [];
        foreach (RoleMatrix::ROLES as $role) {
            foreach ($role['permissions'] as $permission) {
                if (str_starts_with($permission, $prefix)) {
                    $permissions[$permission] = $permission;
                }
            }
        }
        sort($permissions);

        return $permissions;
    }
}
