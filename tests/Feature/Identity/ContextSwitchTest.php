<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\Support\Identity\IdentityTestCase;

/** US1 : lanceur « Mes établissements » et changement de contexte sans reconnexion. */
final class ContextSwitchTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Collège Beta', 'secondary', '#F26B1D', 'logos/beta.png');
        $this->directory->add('t1', 'e2', 'Ecole Alpha', 'primary', '#1D8AF2');
        $this->directory->add('t1', 'e3', 'Maternelle Gamma', 'nursery');
        $this->directory->add('t2', 'x1', 'Autre client', 'higher');
    }

    public function testUnEnseignantVoitSesDeuxEtablissementsAvecSesRoles(): void
    {
        // L'enseignant est un rôle sensible : la 2FA est franchie en base pour isoler ce test du flux 2FA.
        $this->createUser('t1', 'Eve', '+237691000020', [['enseignant', 'establishment', 'e1'], ['parent', 'establishment', 'e2']]);
        $token = $this->tokenAfterTwoFactor('+237691000020');

        $response = $this->call('GET', '/api/v1/me/contexts', $this->auth($token));

        self::assertSame(200, $response->getStatusCode());
        $tiles = $this->json($response)['data'];
        self::assertSame(['e1', 'e2'], array_column($tiles, 'id'));
        self::assertSame('Ecole Alpha', $tiles[1]['name']);
        self::assertSame('primary', $tiles[1]['type']);
        self::assertSame('#1D8AF2', $tiles[1]['accent']);
        self::assertSame('logos/beta.png', $tiles[0]['logo']);
        self::assertSame([['code' => 'enseignant', 'label' => 'Enseignant']], $tiles[0]['roles']);
        self::assertNotContains('group', array_column($tiles, 'kind'));
    }

    public function testDeuxIndicateursSeulementPourLesRolesDeDirectionEtJamaisInventes(): void
    {
        $this->createUser('t1', 'Dan', '+237691000030', [['directeur', 'establishment', 'e1'], ['enseignant', 'establishment', 'e2']]);
        $token = $this->tokenAfterTwoFactor('+237691000030');

        $tiles = $this->json($this->call('GET', '/api/v1/me/contexts', $this->auth($token)))['data'];

        self::assertArrayNotHasKey('indicators', $tiles[1], 'Un enseignant n\'a pas d\'indicateurs de direction.');
        foreach ($tiles[0]['indicators'] ?? [] as $indicator) {
            self::assertContains($indicator['key'], ['people.enrolled', 'billing.outstanding_xaf']);
            self::assertIsNumeric($indicator['value']);
        }
        self::assertLessThanOrEqual(2, count($tiles[0]['indicators'] ?? []));
    }

    public function testChangementDeContexteSansReconnexion(): void
    {
        $this->createUser('t1', 'Eve', '+237691000020', [['enseignant', 'establishment', 'e1'], ['parent', 'establishment', 'e2']]);
        $token = $this->tokenAfterTwoFactor('+237691000020');

        $switch = $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'e2', 'role' => 'parent']);

        self::assertSame(200, $switch->getStatusCode(), (string) $switch->getBody());
        self::assertSame('parent', $this->json($switch)['data']['role']);
        $me = $this->json($this->call('GET', '/api/v1/me', $this->auth($token)));
        self::assertSame(['establishment_id' => 'e2', 'role' => 'parent'], $me['data']['context']);

        // Le même jeton agit maintenant dans l'autre établissement, sans nouvelle connexion.
        $again = $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'e1']);
        self::assertSame('enseignant', $this->json($again)['data']['role']);
    }

    public function testEtablissementOuRoleNonDetenuRefuse(): void
    {
        $this->createUser('t1', 'Eve', '+237691000021', [['parent', 'establishment', 'e2']]);
        $token = $this->token('+237691000021');

        self::assertSame(403, $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'e3'])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'e2', 'role' => 'directeur'])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'x1'])->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'group'])->getStatusCode());
    }

    public function testLaDirectionDeGroupeVoitTousLesEtablissementsEtLaTuileGroupe(): void
    {
        $this->createUser('t1', 'Zoé', '+237691000022', [['direction_groupe', 'tenant', null]]);
        $token = $this->tokenAfterTwoFactor('+237691000022');

        $tiles = $this->json($this->call('GET', '/api/v1/me/contexts', $this->auth($token)))['data'];

        self::assertSame(['group', 'establishment', 'establishment', 'establishment'], array_column($tiles, 'kind'));
        self::assertSame('Tout le groupe', $tiles[0]['name']);
        self::assertNotContains('x1', array_column($tiles, 'id'), 'Aucun établissement d\'un autre client.');
        self::assertSame('group', $this->json($this->call('POST', '/api/v1/me/context', $this->auth($token), ['establishment_id' => 'group']))['data']['context']['id']);
        // Le rôle de niveau client donne accès à tous les établissements du client, et à eux seuls.
        self::assertSame(200, $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'e3'))->getStatusCode());
        self::assertSame(403, $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'x1'))->getStatusCode());
    }

    public function testLeSuperAdminNAPasDeTuileGroupe(): void
    {
        $this->createUser('t1', 'Root', '+237691000023', [['super_admin', 'tenant', null]]);
        $token = $this->tokenAfterTwoFactor('+237691000023');

        $kinds = array_column($this->json($this->call('GET', '/api/v1/me/contexts', $this->auth($token)))['data'], 'kind');

        self::assertNotContains('group', $kinds);
        self::assertCount(3, $kinds);
    }

    public function testSansAucunRoleLaListeEstVide(): void
    {
        $this->createUser('t1', 'Nul', '+237691000024');
        $token = $this->token('+237691000024');

        self::assertSame([], $this->json($this->call('GET', '/api/v1/me/contexts', $this->auth($token)))['data']);
        self::assertSame(['data' => [], 'meta' => ['count' => 0]], $this->json($this->call('GET', '/api/v1/me/todo', $this->auth($token))));
    }

    /** Active la 2FA via l'API puis renvoie un jeton pleinement autorisé. */
    private function tokenAfterTwoFactor(string $phone): string
    {
        $token = (string) $this->json($this->login($phone))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $confirm = $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);
        self::assertSame(200, $confirm->getStatusCode(), (string) $confirm->getBody());

        return $token;
    }
}
