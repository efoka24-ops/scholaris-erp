<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryInfo;
use Tests\Support\Identity\IdentityTestCase;

/** T606 : un rôle territorial sans établissement ouvre les écoles du territoire et seulement celles-ci. */
final class TerritoryAssignmentTest extends IdentityTestCase
{
    private array $director;
    private array $inspector;
    private string $territoryA;
    private string $territoryB;

    protected function setUp(): void
    {
        $territories = new SyntheticTerritoryDirectory();
        $this->boot([TerritoryDirectory::class => $territories]);

        $this->directory->add('t1', 'e1', 'École A1', 'primary');
        $this->directory->add('t1', 'e2', 'École A2', 'primary');
        $this->directory->add('t1', 'e3', 'École A3', 'primary');
        $this->directory->add('t1', 'e4', 'École B1', 'primary');

        $this->director = $this->createUser('t1', 'Diane', '+237691000050', [['super_admin', 'tenant', null]]);
        $token = (string) $this->json($this->login('+237691000050'))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);
        $this->director['token'] = $token;

        $this->inspector = $this->createUser('t1', 'Inspecteur', '+237691000051');
        $this->territoryA = $territories->territoryA;
        $this->territoryB = $territories->territoryB;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function api(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $response = $this->call($method, $path, $headers, $body);

        return [$response, $this->json($response)];
    }

    public function testLeRoleTerritorialSansEtablissementExposeLeBonPerimetre(): void
    {
        [$response, $json] = $this->api('POST', '/api/v1/role-assignments', $this->auth((string) $this->director['token'], 'e1'), [
            'person_id' => $this->inspector['person_id'],
            'role' => 'pub_district_inspector',
            'scope_type' => 'territory',
            'scope_id' => $this->territoryA,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $accessible = $this->kernel->container()->get(AccessibleEstablishments::class)->idsFor('t1', (string) $this->inspector['user_id']);
        self::assertEqualsCanonicalizing(['e1', 'e2', 'e3'], $accessible);

        $checker = $this->kernel->container()->get(PermissionChecker::class);
        $principal = new Principal((string) $this->inspector['user_id'], 't1');
        self::assertTrue($checker->can($principal, 'public_schools.school.read', 'e1'));
        self::assertTrue($checker->can($principal, 'public_schools.school.read', 'e3'));
        self::assertFalse($checker->can($principal, 'public_schools.school.read', 'e4'));

        $scopes = $this->kernel->container()->get(PermissionScopes::class)->scopesFor($principal, 'public_schools.school.read', 'e1');
        self::assertCount(1, $scopes);
        self::assertSame('territory', $scopes[0]->type);
        self::assertSame($this->territoryA, $scopes[0]->id);

        self::assertSame(422, $this->api('POST', '/api/v1/role-assignments', $this->auth((string) $this->director['token'], 'e1'), [
            'person_id' => $this->inspector['person_id'],
            'role' => 'pub_district_inspector',
            'scope_type' => 'territory',
            'scope_id' => $this->territoryA,
            'establishment_id' => 'e1',
        ])[0]->getStatusCode());
    }

    /** TST-05 : un rôle à agrégats seuls est refusé sur `*.named.*`, même si la permission lui a été accordée par erreur. */
    public function testLeDelegueRegionalAAgregatsSeulsEstRefuseSurLesDonneesNominatives(): void
    {
        $delegate = $this->createUser('t1', 'Délégué', '+237691000052');
        foreach ([[$delegate, 'pub_regional_delegate'], [$this->inspector, 'pub_district_inspector']] as [$user, $role]) {
            [$response] = $this->api('POST', '/api/v1/role-assignments', $this->auth((string) $this->director['token'], 'e1'), [
                'person_id' => $user['person_id'], 'role' => $role, 'scope_type' => 'territory', 'scope_id' => $this->territoryA,
            ]);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        }
        $this->pdo()->prepare('INSERT INTO role_permissions (role, permission) VALUES (?, ?)')
            ->execute(['pub_regional_delegate', 'public_schools.pupils.named.read']);

        $checker = $this->kernel->container()->get(PermissionChecker::class);
        $delegatePrincipal = new Principal((string) $delegate['user_id'], 't1');
        self::assertTrue($checker->can($delegatePrincipal, 'public_schools.aggregates.read', 'e1'));
        self::assertFalse($checker->can($delegatePrincipal, 'public_schools.pupils.named.read', 'e1'));
        self::assertSame([], $this->kernel->container()->get(PermissionScopes::class)->scopesFor($delegatePrincipal, 'public_schools.pupils.named.read', 'e1'));
        self::assertTrue($checker->can(new Principal((string) $this->inspector['user_id'], 't1'), 'public_schools.pupils.named.read', 'e1'));
    }
}

/** @internal doule territoriale minimale utilisée par le test T606. */
final class SyntheticTerritoryDirectory implements TerritoryDirectory
{
    public string $territoryA = 'territory-a';
    public string $territoryB = 'territory-b';

    public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo
    {
        return match ($territoryId) {
            $this->territoryA => new TerritoryInfo($tenantId, $this->territoryA, 'arrondissement', 'Territoire A', 'Territory A', null, '2026-01-01T00:00:00+01:00', null, 'v1', 'T-A'),
            $this->territoryB => new TerritoryInfo($tenantId, $this->territoryB, 'arrondissement', 'Territoire B', 'Territory B', null, '2026-01-01T00:00:00+01:00', null, 'v1', 'T-B'),
            default => null,
        };
    }

    public function lineage(string $tenantId, string $territoryId, string $at): array
    {
        return $this->find($tenantId, $territoryId, $at) === null ? [] : [$this->find($tenantId, $territoryId, $at)];
    }

    public function descendantsOf(string $tenantId, string $territoryId, string $at): array
    {
        return $this->lineage($tenantId, $territoryId, $at);
    }

    public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo
    {
        return match ($establishmentId) {
            'e1', 'e2', 'e3' => $this->find($tenantId, $this->territoryA, $at),
            'e4' => $this->find($tenantId, $this->territoryB, $at),
            default => null,
        };
    }

    public function establishmentsIn(string $tenantId, string $territoryId, string $at): array
    {
        return match ($territoryId) {
            $this->territoryA => ['e1', 'e2', 'e3'],
            $this->territoryB => ['e4'],
            default => [],
        };
    }
}
