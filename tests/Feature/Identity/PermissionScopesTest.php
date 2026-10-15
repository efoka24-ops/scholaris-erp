<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Contracts\PermissionScopes;
use Scholaris\Modules\Socle\Identity\Contracts\Scope;
use Tests\Support\Identity\IdentityTestCase;

/** S15 : périmètres d'une permission (établissement, unité, structure, classe) ; can() reste établissement par établissement. */
final class PermissionScopesTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Institut', 'higher');
        $this->directory->add('t1', 'e2', 'Autre institut', 'higher');
        $this->directory->add('t2', 'x1', 'Autre client', 'higher');
    }

    private function scopes(): PermissionScopes
    {
        return $this->kernel->container()->get(PermissionScopes::class);
    }

    /** @param list<Scope> $scopes @return list<string> */
    private static function flat(array $scopes): array
    {
        $out = array_map(static fn (Scope $s): string => $s->type . ':' . $s->id, $scopes);
        sort($out);

        return $out;
    }

    private function assign(string $personId, string $role, string $scope, string $scopeId, string $establishment, ?string $endsAt = null, ?string $startsAt = null): void
    {
        $this->assignRole('t1', $personId, $role, $scope, $scopeId, $endsAt, $startsAt);
        // assignRole rattache le périmètre à son identifiant ; pour une unité, une structure ou une classe, l'établissement est distinct.
        $this->pdo()->prepare('UPDATE role_assignments SET establishment_id = ? WHERE tenant_id = ? AND person_id = ? AND scope_id = ?')
            ->execute([$establishment, 't1', $personId, $scopeId]);
    }

    public function testChaqueAffectationDonneSonPerimetre(): void
    {
        $ids = $this->createUser('t1', 'Paul', '+237691000101');
        $this->assign($ids['person_id'], 'enseignant', 'structure', 'FILIERE-CF', 'e1');
        $this->assign($ids['person_id'], 'enseignant', 'class', 'GROUPE-A', 'e1');
        $this->assign($ids['person_id'], 'directeur', 'org_unit', 'ECOLE-EC', 'e1');
        $principal = new Principal($ids['user_id'], 't1');

        self::assertSame(['class:GROUPE-A', 'structure:FILIERE-CF'], self::flat($this->scopes()->scopesFor($principal, 'grades.enter', 'e1')));
        self::assertSame(['org_unit:ECOLE-EC'], self::flat($this->scopes()->scopesFor($principal, 'organisation.manage', 'e1')));
        self::assertSame([], $this->scopes()->scopesFor($principal, 'permission.inexistante', 'e1'), 'Permission non accordée.');

        $roles = array_map(static fn (Scope $s): string => $s->role, $this->scopes()->scopesFor($principal, 'organisation.manage', 'e1'));
        self::assertSame(['directeur'], $roles);
    }

    public function testRoleDEtablissementOuDeClientDonneToutLEtablissement(): void
    {
        $director = $this->createUser('t1', 'Dir', '+237691000102', [['directeur', 'establishment', 'e1']]);
        $admin = $this->createUser('t1', 'Admin', '+237691000103', [['super_admin', 'tenant', null]]);

        $scopes = $this->scopes()->scopesFor(new Principal($director['user_id'], 't1'), 'organisation.manage', 'e1');
        self::assertSame(['establishment:e1'], self::flat($scopes));
        self::assertTrue($scopes[0]->isWholeEstablishment());
        self::assertSame([], $this->scopes()->scopesFor(new Principal($director['user_id'], 't1'), 'organisation.manage', 'e2'), 'Autre établissement.');

        self::assertSame(['establishment:e2'], self::flat($this->scopes()->scopesFor(new Principal($admin['user_id'], 't1'), 'settings.manage', 'e2')));
    }

    public function testCoherentAvecCanEtIsolation(): void
    {
        $ids = $this->createUser('t1', 'Paul', '+237691000104');
        $this->assign($ids['person_id'], 'enseignant', 'structure', 'FILIERE-CF', 'e1');
        $principal = new Principal($ids['user_id'], 't1');
        $checker = $this->kernel->container()->get(PermissionChecker::class);

        self::assertTrue($checker->can($principal, 'grades.enter', 'e1'), 'can() reste vrai au niveau établissement.');
        self::assertNotSame([], $this->scopes()->scopesFor($principal, 'grades.enter', 'e1'));
        self::assertFalse($checker->can($principal, 'grades.enter', 'e2'));
        self::assertSame([], $this->scopes()->scopesFor($principal, 'grades.enter', 'e2'));

        self::assertSame([], $this->scopes()->scopesFor(new Principal($ids['user_id'], 't2'), 'grades.enter', 'x1'), 'Autre client.');
        self::assertSame([], $this->scopes()->scopesFor($principal, 'grades.enter', 'x1'), 'Établissement d\'un autre client.');
        self::assertSame([], $this->scopes()->scopesFor(new Principal('inconnu', 't1'), 'grades.enter', 'e1'));
    }

    public function testAffectationsExpireesOuFuturesIgnorees(): void
    {
        $ids = $this->createUser('t1', 'Paul', '+237691000105');
        $this->assign($ids['person_id'], 'enseignant', 'structure', 'ANCIENNE', 'e1', endsAt: '2000-01-01T00:00:00+01:00', startsAt: '1999-01-01T00:00:00+01:00');
        $this->assign($ids['person_id'], 'enseignant', 'structure', 'FUTURE', 'e1', startsAt: '2999-01-01T00:00:00+01:00');

        self::assertSame([], $this->scopes()->scopesFor(new Principal($ids['user_id'], 't1'), 'grades.enter', 'e1'));
    }

    public function testAffectationDePerimetreStructureParLApi(): void
    {
        $this->createUser('t1', 'Diane', '+237691000106', [['directeur', 'establishment', 'e1']]);
        $teacher = $this->createUser('t1', 'Paul', '+237691000107');
        $token = (string) $this->json($this->login('+237691000106'))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);

        $response = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'e1') + ['Content-Type' => 'application/json'], [
            'person_id' => $teacher['person_id'], 'role' => 'enseignant', 'scope_type' => 'structure', 'scope_id' => 'FILIERE-CF', 'establishment_id' => 'e1',
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('structure', $this->json($response)['data']['scope_type']);
        self::assertSame(['structure:FILIERE-CF'], self::flat($this->scopes()->scopesFor(new Principal($teacher['user_id'], 't1'), 'grades.enter', 'e1')));

        $bad = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'e1') + ['Content-Type' => 'application/json'], [
            'person_id' => $teacher['person_id'], 'role' => 'enseignant', 'scope_type' => 'filiere', 'scope_id' => 'X', 'establishment_id' => 'e1',
        ]);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('structure', (string) $bad->getBody());
    }
}
