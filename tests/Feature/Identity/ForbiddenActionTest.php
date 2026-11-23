<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Tests\Support\Identity\IdentityTestCase;

/** US1 : une action interdite forcée par appel direct est refusée côté serveur (403), même si l'interface ne l'affiche pas. */
final class ForbiddenActionTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Collège Beta');
        $this->directory->add('t1', 'e2', 'Ecole Alpha', 'primary');
    }

    public function testUnParentNePeutNiAttribuerUnRoleNiCreerUnCompte(): void
    {
        $parent = $this->createUser('t1', 'Paul', '+237691000030', [['parent', 'establishment', 'e1']]);
        $token = $this->token('+237691000030');

        $assign = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'e1'), [
            'person_id' => $parent['person_id'], 'role' => 'directeur', 'scope_type' => 'establishment', 'scope_id' => 'e1',
        ]);
        $create = $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '+237691999999']);
        $list = $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'e1'));

        self::assertSame(403, $assign->getStatusCode());
        self::assertSame(403, $create->getStatusCode());
        self::assertSame(403, $list->getStatusCode());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM role_assignments WHERE role = 'directeur'")->fetchColumn());
    }

    public function testUnDirecteurNAgitQueDansSonEtablissement(): void
    {
        $director = $this->createUser('t1', 'Diane', '+237691000031', [['directeur', 'establishment', 'e1']]);
        $token = $this->twoFactorToken('+237691000031');

        $own = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'e1'), [
            'person_id' => $director['person_id'], 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'e1',
        ]);
        $otherEstablishment = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'e1'), [
            'person_id' => $director['person_id'], 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'e2',
        ]);
        $noAccess = $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'e2'));

        self::assertSame(201, $own->getStatusCode(), (string) $own->getBody());
        self::assertSame(403, $otherEstablishment->getStatusCode());
        self::assertSame(403, $noAccess->getStatusCode());
    }

    public function testUnDirecteurNePeutAttribuerNiSuperAdminNiRoleDeGroupe(): void
    {
        $director = $this->createUser('t1', 'Diane', '+237691000032', [['directeur', 'establishment', 'e1']]);
        $token = $this->twoFactorToken('+237691000032');

        foreach (['super_admin', 'direction_groupe'] as $role) {
            $response = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'e1'), [
                'person_id' => $director['person_id'], 'role' => $role, 'scope_type' => 'tenant',
            ]);
            self::assertSame(403, $response->getStatusCode(), $role);
        }
    }

    public function testPermissionsParContexte(): void
    {
        $director = $this->createUser('t1', 'Diane', '+237691000033', [['directeur', 'establishment', 'e1']]);
        $group = $this->createUser('t1', 'Zoé', '+237691000034', [['direction_groupe', 'tenant', null]]);
        $checker = $this->kernel->container()->get(PermissionChecker::class);
        $d = new Principal($director['user_id'], 't1');
        $g = new Principal($group['user_id'], 't1');

        self::assertTrue($checker->can($d, 'organisation.manage', 'e1'));
        self::assertTrue($checker->can($d, 'settings.manage', 'e1'));
        self::assertFalse($checker->can($d, 'organisation.manage', 'e2'), 'Le directeur n\'a de droits que dans son établissement.');
        self::assertFalse($checker->can($d, 'organisation.manage', null), 'Pas de droit au niveau client.');
        self::assertTrue($checker->can($g, 'organisation.manage', null));
        self::assertTrue($checker->can($g, 'settings.manage', 'e2'));
        self::assertFalse($checker->can($g, 'finance.manage', 'e1'));
        self::assertFalse($checker->can(new Principal($director['user_id'], 't2'), 'organisation.manage', 'e1'), 'Même utilisateur, autre client : refus.');
        self::assertFalse($checker->can(new Principal('inconnu', 't1'), 'organisation.read', 'e1'));
    }

    public function testCreationDeCompteParUnDirecteurEtActivation(): void
    {
        $this->createUser('t1', 'Diane', '+237691000035', [['directeur', 'establishment', 'e1']]);
        $token = $this->twoFactorToken('+237691000035');

        $created = $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => 'Awa', 'last_name' => 'Njoya', 'phone' => '691 22 33 44']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('pending', $this->json($created)['data']['status']);
        self::assertSame(409, $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => 'Awa', 'last_name' => 'Bis', 'phone' => '+237691223344'])->getStatusCode());
        self::assertSame(422, $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), ['first_name' => '', 'last_name' => 'Njoya'])->getStatusCode());

        $request = $this->call('POST', '/api/v1/auth/activation/request', [], ['identifier' => '+237691223344']);
        self::assertSame(200, $request->getStatusCode());
        self::assertNotNull($this->sms->lastCode('+237691223344'));
    }

    public function testJetonInvalideOuAbsent(): void
    {
        self::assertSame(401, $this->call('GET', '/api/v1/me')->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth('sch_inconnu'))->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/role-assignments', ['X-Establishment-Id' => 'e1'])->getStatusCode());
    }

    private function twoFactorToken(string $phone): string
    {
        $token = (string) $this->json($this->login($phone))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);

        return $token;
    }
}
