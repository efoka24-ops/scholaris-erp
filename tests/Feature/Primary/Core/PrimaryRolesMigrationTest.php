<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Core;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Primary\Policies\RoleMatrix;

/** La migration des permissions primary.* concorde avec RoleMatrix::EXTRA (aucune permission accordée sans être déclarée, et inversement). */
final class PrimaryRolesMigrationTest extends TestCase
{
    public function testMigrationEtMatriceConcordent(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/database/migrations/202610211303_primary_permissions.sql');
        preg_match_all("/VALUES \('([a-z_]+)', '([a-z_.]+)'\)/", $sql, $matches, PREG_SET_ORDER);
        $granted = [];
        foreach ($matches as $m) {
            $granted[$m[1]][] = $m[2];
        }
        foreach (RoleMatrix::EXTRA as $role => $permissions) {
            self::assertEqualsCanonicalizing($permissions, $granted[$role] ?? [], $role);
        }
        self::assertSame(array_keys(RoleMatrix::EXTRA), array_keys($granted));
    }

    public function testMigrationP002EtMatriceConcordent(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/database/migrations/202610231411_primary_permissions_p002.sql');
        preg_match_all("/VALUES \('([a-z_]+)', '([a-z_.]+)'\)/", $sql, $matches, PREG_SET_ORDER);
        $granted = [];
        foreach ($matches as $m) {
            $granted[$m[1]][] = $m[2];
        }
        foreach (RoleMatrix::EXTRA_P002 as $role => $permissions) {
            self::assertEqualsCanonicalizing($permissions, $granted[$role] ?? [], $role);
        }
        self::assertSame(array_keys(RoleMatrix::EXTRA_P002), array_keys($granted));
    }

    public function testToutePermissionDeLaMatriceEstDansUneMigration(): void
    {
        $all = '';
        foreach (glob(dirname(__DIR__, 4) . '/database/migrations/*.sql') ?: [] as $file) {
            $all .= (string) file_get_contents($file);
        }
        foreach (RoleMatrix::ROLES as $row) {
            foreach ($row['permissions'] as $permission) {
                if (str_starts_with($permission, 'primary.')) {
                    self::assertStringContainsString("'" . $permission . "'", $all, $permission);
                }
            }
        }
    }
}
