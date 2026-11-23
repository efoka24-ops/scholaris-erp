<?php

declare(strict_types=1);

namespace Tests\Isolation;

use InvalidArgumentException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Identity\IdentityTestCase;

/** Cloisonnement tenant/établissement et refus par défaut des périmètres territoriaux synthétiques. */
final class PublicSchoolsScopeTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('tenant-syn-1', 'school-syn-1', 'École synthétique 1', 'primary');
        $this->directory->add('tenant-syn-1', 'school-syn-2', 'École synthétique 2', 'primary');
        $this->directory->add('tenant-syn-2', 'school-syn-3', 'École synthétique 3', 'primary');
    }

    public function testUnRoleNePeutPasFranchirLeTenantOuLEtablissementDemande(): void
    {
        $user = $this->createUser('tenant-syn-1', 'Utilisateur synthétique', '+237690000801', [
            ['directeur', 'establishment', 'school-syn-1'],
        ]);
        $scopes = $this->kernel->container()->get(PermissionScopes::class);
        $principal = new Principal($user['user_id'], 'tenant-syn-1');

        $allowed = $scopes->scopesFor($principal, 'organisation.manage', 'school-syn-1');
        self::assertCount(1, $allowed);
        self::assertSame('tenant-syn-1', $allowed[0]->tenantId);
        self::assertSame('school-syn-1', $allowed[0]->establishmentId);
        self::assertSame([], $scopes->scopesFor($principal, 'organisation.manage', 'school-syn-2'));
        self::assertSame([], $scopes->scopesFor($principal, 'organisation.manage', 'school-syn-3'));
        self::assertSame([], $scopes->scopesFor(new Principal($user['user_id'], 'tenant-syn-2'), 'organisation.manage', 'school-syn-3'));
        self::assertSame([], $scopes->scopesFor(new Principal('unknown-syn-user', 'tenant-syn-1'), 'organisation.manage', 'school-syn-1'));
    }

    public function testUnScopeTerritorialExigeUnContexteTenantEtEtablissement(): void
    {
        $scope = Scope::territory('01J9Z6Q0A1B2C3D4E5F6G7H8J5', 'superviseur-syn', 'tenant-syn-1', 'school-syn-1');

        self::assertTrue($scope->isTerritory());
        self::assertSame(Scope::TERRITORY, $scope->type);
        self::assertSame('01J9Z6Q0A1B2C3D4E5F6G7H8J5', $scope->id);
        self::assertSame('tenant-syn-1', $scope->tenantId);
        self::assertSame('school-syn-1', $scope->establishmentId);
        self::assertFalse($scope->isWholeEstablishment());
    }

    public function testUnScopeTerritorialSansFrontiereDeTenantEstRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Scope(Scope::TERRITORY, 'SYN-territory-ulid-02', 'superviseur-syn');
    }
}
