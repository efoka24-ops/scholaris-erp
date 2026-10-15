<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher;

use Scholaris\Modules\Apps\Higher\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Application Supérieur (IPES) : manifeste lu par le catalogue de la plateforme (clé de conteneur `platform.applications.higher`),
 * vocabulaire de l'interface, rôles R01-R23 et permissions `higher.*`.
 *
 * Conventions imposées à tous les sous-modules (vérifiées par tests/Architecture/HigherConventionsTest) :
 *  - tables préfixées `hi_`, avec `tenant_id` et `establishment_id` obligatoires, ids ULID CHAR(26), dates VARCHAR(32) ISO 8601,
 *    montants entiers XAF, aucun mot réservé MySQL 8 ;
 *  - paramètres sous les clés `higher.*` (lues par Organisation\Contracts\SettingsReader / OrgUnitSettingsReader) ;
 *  - seuls les `Contracts/` du socle sont utilisés ; toute route est enregistrée par Support\HigherRoutes (écritures derrière WriteGate).
 */
final class HigherApplication
{
    public const KEY = 'higher';
    public const TARGET = 'higher';
    public const VERSION = '0.1.0';
    public const LABEL = 'Supérieur (IPES)';
    public const TABLE_PREFIX = 'hi_';
    public const SETTINGS_PREFIX = 'higher.';
    public const PERMISSION_PREFIX = 'higher.';
    public const CONTAINER_KEY = 'platform.applications.higher';

    /** Modules du socle activés pour les établissements `higher` à l'ouverture de l'application (fait par Platform, pas par Higher). */
    public const REQUIRED_MODULES = ['people', 'billing', 'messaging', 'documents', 'scheduling', 'attendance', 'assessment', 'staff', 'reporting'];

    /** Sous-modules prévus (vagues 1 à 3) ; chacun a son dossier, son routes.php et son definitions.php. */
    public const MODULES = [
        'Academic', 'Admissions', 'Enrolment', 'Curriculum', 'Teaching', 'Attendance', 'Grades', 'Deliberation', 'Billing',
        'Internships', 'NationalExams', 'Tutelage', 'Documents', 'Dashboards', 'Portals', 'Notifications',
    ];

    /** Vocabulaire de l'interface (RG-IHM-5) : terme générique => terme affiché. */
    public const VOCABULARY = ['learner' => 'étudiant', 'grouping' => 'filière', 'period' => 'semestre', 'document' => 'relevé'];

    private function __construct()
    {
    }

    public static function manifest(): ApplicationManifest
    {
        return new ApplicationManifest(
            key: self::KEY,
            label: self::LABEL,
            target: self::TARGET,
            status: ApplicationManifest::AVAILABLE,
            version: self::VERSION,
            requiredModules: self::REQUIRED_MODULES,
            monthlyPriceXaf: 60000,
            trialDays: 90,
            graceDays: 15,
        );
    }

    public static function descriptor(): ApplicationDescriptor
    {
        return new ApplicationDescriptor(self::TARGET, 'Supérieur', self::VOCABULARY);
    }

    /** @return list<string> codes des rôles exercés dans l'application (R01-R23, rôles du socle réutilisés compris) */
    public static function roles(): array
    {
        $codes = [];
        foreach (RoleMatrix::ROLES as $role) {
            $codes[$role['code']] = $role['code'];
        }

        return array_values($codes);
    }

    /** @return list<string> permissions `higher.*` déclarées par l'application (triées) */
    public static function permissions(): array
    {
        $permissions = [];
        foreach (RoleMatrix::ROLES as $role) {
            foreach ($role['permissions'] as $permission) {
                if (str_starts_with($permission, self::PERMISSION_PREFIX)) {
                    $permissions[$permission] = $permission;
                }
            }
        }
        sort($permissions);

        return $permissions;
    }
}
