<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary;

use Scholaris\Modules\Apps\Primary\Policies\RoleMatrix;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationManifest;

final class PrimaryApplication
{
    public const KEY = 'primary';
    public const CONTAINER_KEY = 'platform.applications.primary';
    public const PERMISSION_PREFIX = 'primary.';
    public const MODULES = ['Academic', 'Enrolment', 'Grades', 'Attendance', 'Billing', 'Documents', 'ClassLife', 'Dashboards', 'Portals', 'Notifications'];

    private function __construct()
    {
    }

    public static function manifest(): ApplicationManifest
    {
        return new ApplicationManifest(self::KEY, 'École primaire', self::KEY, ApplicationManifest::COMING_SOON, '0.1.0', ['people', 'billing', 'messaging', 'documents', 'attendance', 'assessment', 'reporting'], 0, 90, 15);
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_values(array_unique(array_column(RoleMatrix::ROLES, 'code')));
    }

    /** @return list<string> */
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
