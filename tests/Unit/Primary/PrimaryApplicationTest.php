<?php

declare(strict_types=1);

namespace Tests\Unit\Primary;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Primary\Policies\RoleMatrix;
use Scholaris\Modules\Apps\Primary\PrimaryApplication;
use Scholaris\Modules\Apps\Primary\Support\Perimeter;

final class PrimaryApplicationTest extends TestCase
{
    public function testManifestIsComingSoonAndHasPlatformKey(): void
    {
        $manifest = PrimaryApplication::manifest();
        self::assertSame('primary', $manifest->key);
        self::assertSame('primary', $manifest->target);
        self::assertSame('coming_soon', $manifest->status);
    }

    public function testRoleListContainsPrimaryRolesAndDeduplicatesSharedTeacherRole(): void
    {
        self::assertContains('primary_directeur', PrimaryApplication::roles());
        self::assertSame(1, count(array_filter(PrimaryApplication::roles(), static fn (string $role): bool => $role === 'enseignant')));
    }

    public function testPermissionsAreSortedAndApplicationScoped(): void
    {
        $permissions = PrimaryApplication::permissions();
        $sorted = $permissions;
        sort($sorted);
        self::assertSame($sorted, $permissions);
        self::assertContains('primary.settings.manage', $permissions);
    }

    public function testClassPerimeterDoesNotLeakAcrossClasses(): void
    {
        $perimeter = new Perimeter('tenant-a', 'school-a', false, ['class-a']);
        self::assertTrue($perimeter->coversClass('class-a'));
        self::assertFalse($perimeter->coversClass('class-b'));
        self::assertFalse($perimeter->coversClass(null));
    }

    public function testWholeEstablishmentPerimeterCoversAnyClass(): void
    {
        $perimeter = new Perimeter('tenant-a', 'school-a', true);
        self::assertTrue($perimeter->coversClass('class-b'));
    }

    public function testMatrixNamesOnlyDeclaredRoles(): void
    {
        foreach (RoleMatrix::MATRIX as $domain => $roles) {
            self::assertNotSame('', $domain);
            foreach (array_keys($roles) as $role) {
                self::assertArrayHasKey($role, RoleMatrix::ROLES);
            }
        }
    }
}
