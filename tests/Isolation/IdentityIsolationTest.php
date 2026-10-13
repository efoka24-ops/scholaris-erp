<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Tests\Support\Identity\IdentityTestCase;

/** Deux clients : aucun accès, aucune fuite et aucune modification d'un client à l'autre. */
final class IdentityIsolationTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Collège Beta');
        $this->directory->add('t2', 'x1', 'Lycée Omega');
    }

    public function testUnUtilisateurDuClientANAccedeAuxEtablissementsDuClientB(): void
    {
        $this->createUser('t1', 'Zoé', '+237691000060', [['direction_groupe', 'tenant', null]]);
        $token = (string) $this->json($this->login('+237691000060'))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);

        self::assertSame(200, $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'e1'))->getStatusCode());
        self::assertSame(403, $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'x1'))->getStatusCode());
        $ids = array_column($this->json($this->call('GET', '/api/v1/me/contexts', $this->auth($token)))['data'], 'id');
        self::assertNotContains('x1', $ids);
        self::assertSame(403, $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'x1'])->getStatusCode());
    }

    public function testUneAffectationDuClientANEstNiVisibleNiSupprimablePourLeClientB(): void
    {
        $victim = $this->createUser('t1', 'Vic', '+237691000061', [['comptable', 'establishment', 'e1']]);
        $assignmentId = (string) $this->pdo()->query("SELECT id FROM role_assignments WHERE person_id = '" . $victim['person_id'] . "'")->fetchColumn();
        $this->createUser('t2', 'Dir', '+237691000062', [['direction_groupe', 'tenant', null]]);
        $token = (string) $this->json($this->login('+237691000062'))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);

        $list = $this->json($this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'x1')));
        $delete = $this->call('DELETE', '/api/v1/role-assignments/' . $assignmentId, $this->auth($token, 'x1'));
        $attach = $this->call('POST', '/api/v1/role-assignments', $this->auth($token, 'x1'), [
            'person_id' => $victim['person_id'], 'role' => 'comptable', 'scope_type' => 'establishment', 'scope_id' => 'x1',
        ]);

        self::assertNotContains($assignmentId, array_column($list['data'], 'id'));
        self::assertSame(404, $delete->getStatusCode());
        self::assertSame(422, $attach->getStatusCode(), 'La personne d\'un autre client est introuvable.');
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM role_assignments WHERE person_id = \'' . $victim['person_id'] . '\'')->fetchColumn());
    }

    public function testUnJetonNeFonctionneQuePourSonClientEtLesAppareilsSontCloisonnes(): void
    {
        $this->createUser('t1', 'A', '+237691000063');
        $this->createUser('t2', 'B', '+237691000064');
        $a = $this->token('+237691000063');
        $b = $this->token('+237691000064');

        $devicesA = array_column($this->json($this->call('GET', '/api/v1/me/devices', $this->auth($a)))['data'], 'id');
        $devicesB = array_column($this->json($this->call('GET', '/api/v1/me/devices', $this->auth($b)))['data'], 'id');

        self::assertCount(1, $devicesA);
        self::assertSame([], array_intersect($devicesA, $devicesB));
        self::assertSame(404, $this->call('DELETE', '/api/v1/me/devices/' . $devicesB[0], $this->auth($a))->getStatusCode());
    }

    public function testMemeTelephoneDansDeuxClientsLeMotDePasseDepartage(): void
    {
        $this->createUser('t1', 'A', '+237691000065');
        $other = $this->createUser('t2', 'B', '+237691000065', active: false);
        $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Infrastructure\PdoUserRepository::class)->setPassword(
            't2',
            $other['user_id'],
            $this->kernel->container()->get(\Scholaris\Modules\Socle\Identity\Application\PasswordHasher::class)->hash('Autre-Mot-De-Passe-Long-9'),
            '2026-10-08T10:00:00+01:00',
        );

        $first = $this->json($this->login('+237691000065'));
        $second = $this->json($this->login('+237691000065', 'Autre-Mot-De-Passe-Long-9'));

        self::assertNotSame($first['user']['id'], $second['user']['id']);
        self::assertSame($other['user_id'], $second['user']['id']);
    }
}
