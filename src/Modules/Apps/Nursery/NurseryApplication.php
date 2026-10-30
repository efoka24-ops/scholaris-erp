<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Nursery;

use Scholaris\Modules\Apps\Nursery\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Organisation\Contracts\ApplicationDescriptor;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

/**
 * Application Maternelle privée : manifeste lu par le catalogue de la plateforme (clé `platform.applications.nursery`) et
 * vocabulaire de l'interface. Le statut reste `coming_soon` jusqu'à la recette et à l'accord écrit de l'utilisateur (T1096) : le
 * catalogue en base fait foi pour le commercial (tarif, essai, grâce).
 */
final class NurseryApplication
{
    public const KEY = 'nursery';
    public const TARGET = 'nursery';
    public const LABEL = 'École maternelle';
    public const VERSION = '0.1.0';
    public const CONTAINER_KEY = 'platform.applications.nursery';
    public const PERMISSION_PREFIX = 'nursery.';
    public const MODULES = ['Core', 'Sections', 'Enrolment', 'Attendance', 'Pickup', 'Development', 'Billing', 'Messages', 'Portal', 'Documents', 'Services', 'Transition', 'Dashboard'];

    /** @var array<string, string> */
    public const VOCABULARY = [
        'learner' => 'enfant',
        'grouping' => 'section',
        'period' => 'trimestre',
        'document' => 'carnet de suivi',
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
            requiredModules: ['people', 'billing', 'messaging', 'documents', 'attendance', 'scheduling', 'staff', 'reporting'],
            monthlyPriceXaf: 30000,
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

    /** @return list<string> permissions `nursery.*` déclarées par la matrice, triées */
    public static function permissions(): array
    {
        $permissions = [];
        foreach (RoleMatrix::ROLES as $role) {
            foreach ($role['permissions'] as $permission) {
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
