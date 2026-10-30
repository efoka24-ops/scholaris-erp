<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary;

use Scholaris\Modules\Apps\Secondary\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Application Enseignement secondaire : UN SEUL produit `secondary` pour les 7 types d'établissement (décision du propriétaire,
 * 22 octobre 2026). Le type ne change pas le code : il choisit un profil (paramètres) et des modules activables
 * (Setup\Application\ProfileResolver, Support\ModuleGate).
 *
 * Manifeste lu par le catalogue (`platform.applications.secondary`) : statut `coming_soon` jusqu'à la recette pilote (T979) ; le
 * catalogue en base fait foi pour le commercial (60 000 XAF par mois, 90 jours d'essai, 15 jours de grâce, inchangés).
 */
final class SecondaryApplication
{
    public const KEY = 'secondary';
    public const TARGET = 'secondary';
    public const LABEL = 'Enseignement secondaire';
    public const VERSION = '0.1.0';
    public const CONTAINER_KEY = 'platform.applications.secondary';
    public const PERMISSION_PREFIX = 'secondary.';
    /** Sous-modules prévus (plan §2) ; un dossier absent n'est simplement pas chargé par les glob racine. */
    public const MODULES = ['Setup', 'Catalogue', 'Enrolment', 'CarteScolaire', 'Fees', 'Timetable', 'Syllabus', 'Discipline', 'Grades', 'Councils', 'OfficialExams', 'Workshops', 'Staff', 'Services', 'Documents', 'Notifications', 'Dashboards', 'Portals'];

    /** @var array<string, string> */
    public const VOCABULARY = [
        'learner' => 'élève',
        'grouping' => 'classe',
        'period' => 'séquence',
        'document' => 'bulletin',
    ];

    private function __construct()
    {
    }

    public static function manifest(): ApplicationManifest
    {
        return new ApplicationManifest(
            key: self::KEY,
            label: self::LABEL,
            target: self::TARGET,
            status: ApplicationManifest::COMING_SOON,
            version: self::VERSION,
            requiredModules: ['people', 'billing', 'messaging', 'documents', 'scheduling', 'attendance', 'assessment', 'staff', 'reporting'],
            monthlyPriceXaf: 60000,
            trialDays: 90,
            graceDays: 15,
        );
    }

    public static function descriptor(): ApplicationDescriptor
    {
        return new ApplicationDescriptor(self::TARGET, self::LABEL, self::VOCABULARY);
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_values(array_unique(array_column(RoleMatrix::ROLES, 'code')));
    }

    /** @return list<string> permissions `secondary.*` déclarées par la matrice (rôles et ajouts du squelette), triées */
    public static function permissions(): array
    {
        $permissions = [];
        foreach (RoleMatrix::ROLES as $role) {
            foreach (RoleMatrix::permissionsOf($role['code']) as $permission) {
                if (str_starts_with($permission, self::PERMISSION_PREFIX)) {
                    $permissions[$permission] = true;
                }
            }
        }
        $result = array_keys($permissions);
        sort($result);

        return $result;
    }
}
