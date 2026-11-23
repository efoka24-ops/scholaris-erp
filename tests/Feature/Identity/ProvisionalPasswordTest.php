<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorPolicy;
use Scholaris\Modules\Socle\Identity\Contracts\AccountAdministration;
use Tests\Support\Identity\IdentityTestCase;

/**
 * C-001 : connexion simple. Le personnel se connecte par e-mail + mot de passe ; le premier mot de passe est un mot de passe
 * provisoire généré à la création du compte, à remplacer à la première connexion. La double authentification des rôles de
 * l'établissement est désactivée par le réglage d'exploitation (AUTH_2FA_REQUIRED=false).
 */
final class ProvisionalPasswordTest extends IdentityTestCase
{
    private const DIRECTOR = 'diane@ecole.test';
    private const TEACHER = 'paul@ecole.test';

    protected function setUp(): void
    {
        $this->boot([TwoFactorPolicy::class => new TwoFactorPolicy(Config::fromArray(['app' => ['two_factor_required' => false]]))]);
        $this->directory->add('t1', 'e1', 'Collège Alpha', 'secondary');
        $this->directory->add('t1', 'e2', 'Collège Beta', 'secondary');
        $this->directory->add('t2', 'f1', 'Lycée Gamma', 'secondary');
        $this->createUser('t1', 'Diane', '+237691000010', [['directeur', 'establishment', 'e1']], self::DIRECTOR);
        $this->createUser('t2', 'Gaston', '+237691000090', [['directeur', 'establishment', 'f1']], 'gaston@lycee.test');
    }

    /** @return array<string, mixed> */
    private function createStaff(string $token, string $email = self::TEACHER, string $first = 'Paul'): array
    {
        $response = $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), [
            'first_name' => $first, 'last_name' => 'Ngono', 'email' => $email, 'provisional_password' => true,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), 'Un secret n\'est jamais mis en cache.');

        return $this->json($response)['data'];
    }

    public function testLaCreationRendUnMotDePasseProvisoireUneSeuleFoisEtLeStockeHache(): void
    {
        $director = $this->token(self::DIRECTOR);
        $created = $this->createStaff($director);
        $password = (string) $created['provisional_password'];

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-np-z2-9]{12}$/', $password, '12 caractères, sans 0 O 1 l I o.');
        self::assertMatchesRegularExpression('/[A-Z]/', $password);
        self::assertMatchesRegularExpression('/[a-z]/', $password);
        self::assertMatchesRegularExpression('/\d/', $password);
        self::assertSame('active', $created['status']);
        self::assertTrue($created['must_change_password']);
        self::assertSame(self::TEACHER, $created['login']);

        $row = $this->rows('SELECT password_hash, must_change_password FROM users WHERE email = ?', [self::TEACHER])[0];
        self::assertStringStartsWith('$argon2id$', (string) $row['password_hash']);
        self::assertSame(1, (int) $row['must_change_password']);
        self::assertStringNotContainsString($password, (string) json_encode($this->rows('SELECT * FROM audit_log')), 'Jamais dans le journal.');
        $audit = (string) $this->scalar('SELECT action FROM audit_log WHERE action = ?', ['identity.provisional_password_issued']);
        self::assertSame('identity.provisional_password_issued', $audit);
    }

    public function testSansLeDrapeauLeParcoursParCodeEstInchange(): void
    {
        $director = $this->token(self::DIRECTOR);
        $response = $this->call('POST', '/api/v1/users', $this->auth($director, 'e1'), ['first_name' => 'Luc', 'last_name' => 'Eto', 'email' => 'luc@ecole.test']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', $this->json($response)['data']['status']);
        self::assertArrayNotHasKey('provisional_password', $this->json($response)['data']);
    }

    public function testPremiereConnexionSessionLimiteeAuChangementPuisAccesComplet(): void
    {
        $director = $this->token(self::DIRECTOR);
        $provisional = (string) $this->createStaff($director)['provisional_password'];

        $login = $this->login(self::TEACHER, $provisional);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $data = $this->json($login);
        self::assertTrue($data['password_change_required']);
        self::assertFalse($data['two_factor_required']);
        $token = (string) $data['token'];

        $blocked = $this->call('GET', '/api/v1/me/contexts', $this->auth($token));
        self::assertSame(403, $blocked->getStatusCode());
        self::assertStringEndsWith('password-change-required', $this->json($blocked)['type']);
        self::assertSame(403, $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'e1'))->getStatusCode());
        $me = $this->json($this->call('GET', '/api/v1/me', $this->auth($token)));
        self::assertTrue($me['data']['password_change_required']);

        $changed = $this->call('POST', '/api/v1/auth/change-password', $this->auth($token), [
            'current_password' => $provisional, 'new_password' => 'Girafe-Bleue-Soleil-58', 'new_password_confirmation' => 'Girafe-Bleue-Soleil-58',
        ]);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth($token))->getStatusCode());
        self::assertFalse($this->json($this->call('GET', '/api/v1/me', $this->auth($token)))['data']['password_change_required']);

        self::assertSame(401, $this->login(self::TEACHER, $provisional)->getStatusCode(), 'Le mot de passe provisoire ne sert plus.');
        $again = $this->json($this->login(self::TEACHER, 'Girafe-Bleue-Soleil-58'));
        self::assertFalse($again['password_change_required']);
        self::assertSame('identity.password_changed', (string) $this->scalar('SELECT action FROM audit_log WHERE action = ?', ['identity.password_changed']));
    }

    public function testLeChangementRefuseUnMotDePasseFaibleIdentiqueOuUneConfirmationDifferente(): void
    {
        $provisional = (string) $this->createStaff($this->token(self::DIRECTOR))['provisional_password'];
        $token = (string) $this->json($this->login(self::TEACHER, $provisional))['token'];
        $change = fn (array $body): \Psr\Http\Message\ResponseInterface => $this->call('POST', '/api/v1/auth/change-password', $this->auth($token), $body);

        self::assertSame(422, $change(['current_password' => $provisional, 'new_password' => 'court'])->getStatusCode());
        self::assertSame(422, $change(['current_password' => $provisional, 'new_password' => '1234567890'])->getStatusCode());
        self::assertSame(422, $change(['current_password' => $provisional, 'new_password' => $provisional])->getStatusCode());
        self::assertSame(422, $change(['current_password' => $provisional, 'new_password' => 'paul@ecole.test-2026'])->getStatusCode(), 'Contient l\'identifiant.');
        self::assertSame(422, $change(['current_password' => $provisional, 'new_password' => 'Girafe-Bleue-Soleil-58', 'new_password_confirmation' => 'autre'])->getStatusCode());
        // Les refus de forme ne comptent pas comme des échecs de connexion.
        self::assertSame(200, $change(['current_password' => $provisional, 'new_password' => 'Girafe-Bleue-Soleil-58'])->getStatusCode());
    }

    public function testMauvaisMotDePasseActuelCompteLesEchecsPuisVerrouilleCommeLaConnexion(): void
    {
        $provisional = (string) $this->createStaff($this->token(self::DIRECTOR))['provisional_password'];
        $token = (string) $this->json($this->login(self::TEACHER, $provisional))['token'];
        $try = fn (): \Psr\Http\Message\ResponseInterface => $this->call('POST', '/api/v1/auth/change-password', $this->auth($token), ['current_password' => 'Faux-Faux-Faux-1', 'new_password' => 'Girafe-Bleue-Soleil-58']);

        $first = $try();
        self::assertSame(422, $first->getStatusCode(), 'Pas de 401 : la session reste valable.');
        self::assertSame(4, $this->json($first)['attempts_left']);
    }

    public function testCinqEchecsVerrouillentLeChangementEtLaConnexion(): void
    {
        $provisional = (string) $this->createStaff($this->token(self::DIRECTOR))['provisional_password'];
        $token = (string) $this->json($this->login(self::TEACHER, $provisional))['token'];
        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->call('POST', '/api/v1/auth/change-password', $this->auth($token), ['current_password' => 'Faux-Faux-Faux-1', 'new_password' => 'Girafe-Bleue-Soleil-58'])->getStatusCode();
        }
        self::assertSame([422, 422, 422, 422, 423], $statuses);
        $locked = $this->call('POST', '/api/v1/auth/change-password', $this->auth($token), ['current_password' => $provisional, 'new_password' => 'Girafe-Bleue-Soleil-58']);
        self::assertSame(423, $locked->getStatusCode());
        self::assertNotSame('', $locked->getHeaderLine('Retry-After'));

        self::assertSame(423, $this->login(self::TEACHER, $provisional)->getStatusCode(), 'Mêmes compteurs que la connexion.');
        $this->clock->advance(901);
        self::assertSame(200, $this->login(self::TEACHER, $provisional)->getStatusCode());
    }

    public function testLaConnexionNeDistinguePasInconnuEtIncorrect(): void
    {
        $this->createStaff($this->token(self::DIRECTOR));
        $wrong = $this->login(self::TEACHER, 'Faux-Faux-Faux-1');
        $unknown = $this->login('personne@ecole.test', 'Faux-Faux-Faux-1');

        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame($wrong->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($this->json($wrong)['type'], $this->json($unknown)['type']);
        self::assertSame($this->json($wrong)['detail'], $this->json($unknown)['detail']);
    }

    public function testLeCompteDUnAutreClientEstInvisibleEtLeMemeEmailPeutExister(): void
    {
        $created = $this->createStaff($this->token(self::DIRECTOR));
        $other = $this->token('gaston@lycee.test');

        $response = $this->call('POST', '/api/v1/users/' . $created['user_id'] . '/provisional-password', $this->auth($other, 'f1'), []);
        self::assertSame(404, $response->getStatusCode(), 'Le client t2 ne voit pas les comptes de t1.');
    }

    public function testReemissionInvalideLAncienMotDePasseEtLesSessions(): void
    {
        $director = $this->token(self::DIRECTOR);
        $created = $this->createStaff($director);
        $first = (string) $created['provisional_password'];
        $teacherToken = (string) $this->json($this->login(self::TEACHER, $first))['token'];
        $this->call('POST', '/api/v1/auth/change-password', $this->auth($teacherToken), ['current_password' => $first, 'new_password' => 'Girafe-Bleue-Soleil-58']);
        $this->assignRole('t1', (string) $created['person_id'], 'enseignant', 'establishment', 'e1');
        $sessionToken = $this->token(self::TEACHER, 'Girafe-Bleue-Soleil-58');

        $response = $this->call('POST', '/api/v1/users/' . $created['user_id'] . '/provisional-password', $this->auth($director, 'e1'), []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $second = (string) $this->json($response)['data']['provisional_password'];

        self::assertNotSame($first, $second);
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($sessionToken))->getStatusCode(), 'Sessions révoquées.');
        self::assertSame(401, $this->login(self::TEACHER, 'Girafe-Bleue-Soleil-58')->getStatusCode(), 'Ancien mot de passe invalide.');
        self::assertTrue($this->json($this->login(self::TEACHER, $second))['password_change_required']);
    }

    public function testLaReemissionAccepteAussiLIdentifiantDeLaPersonne(): void
    {
        $director = $this->token(self::DIRECTOR);
        $created = $this->createStaff($director);

        $response = $this->call('POST', '/api/v1/users/' . $created['person_id'] . '/provisional-password', $this->auth($director, 'e1'), []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($created['user_id'], $this->json($response)['data']['user_id']);
    }

    public function testGardeFousDeLaReemission(): void
    {
        $director = $this->token(self::DIRECTOR);
        $self = $this->userIdOf(self::DIRECTOR);
        self::assertSame(409, $this->call('POST', '/api/v1/users/' . $self . '/provisional-password', $this->auth($director, 'e1'), [])->getStatusCode(), 'Pas son propre compte.');

        // Un directeur ne reprend pas la main sur un administrateur du client.
        $admin = $this->createUser('t1', 'Sam', '+237691000011', [['super_admin', 'tenant', 't1']], 'sam@ecole.test');
        self::assertSame(403, $this->call('POST', '/api/v1/users/' . $admin['user_id'] . '/provisional-password', $this->auth($director, 'e1'), [])->getStatusCode());
        $superToken = $this->token('sam@ecole.test');
        $created = $this->createStaff($superToken);
        self::assertSame(200, $this->call('POST', '/api/v1/users/' . $created['user_id'] . '/provisional-password', $this->auth($superToken, 'e1'), [])->getStatusCode());

        // Une personne qui n'exerce rien dans cet établissement mais ailleurs dans le client n'est pas atteignable.
        $elsewhere = $this->createUser('t1', 'Eve', '+237691000012', [['enseignant', 'establishment', 'e2']], 'eve@ecole.test');
        self::assertSame(404, $this->call('POST', '/api/v1/users/' . $elsewhere['user_id'] . '/provisional-password', $this->auth($director, 'e1'), [])->getStatusCode());
    }

    public function testCinqReemissionsParHeureEtParCompte(): void
    {
        $director = $this->token(self::DIRECTOR);
        $created = $this->createStaff($director);
        $path = '/api/v1/users/' . $created['user_id'] . '/provisional-password';
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $this->call('POST', $path, $this->auth($director, 'e1'), [])->getStatusCode());
        }
        $limited = $this->call('POST', $path, $this->auth($director, 'e1'), []);
        self::assertSame(429, $limited->getStatusCode());
        self::assertNotSame('', $limited->getHeaderLine('Retry-After'));
        $this->clock->advance(3601);
        self::assertSame(200, $this->call('POST', $path, $this->auth($director, 'e1'), [])->getStatusCode());
    }

    public function testUnApprenantNePeutNiCreerNiReemettre(): void
    {
        $learner = $this->createUser('t1', 'Lea', '+237691000013', [['apprenant', 'establishment', 'e1']], 'lea@ecole.test');
        $token = $this->token('lea@ecole.test');
        $body = ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@ecole.test', 'provisional_password' => true];

        self::assertSame(403, $this->call('POST', '/api/v1/users', $this->auth($token, 'e1'), $body)->getStatusCode());
        self::assertSame(403, $this->call('POST', '/api/v1/users/' . $learner['user_id'] . '/provisional-password', $this->auth($token, 'e1'), [])->getStatusCode());
    }

    public function testLeContratAccountAdministrationFournitLeMotDePasseAuxImports(): void
    {
        $actor = $this->userIdOf(self::DIRECTOR);
        $accounts = $this->kernel->container()->get(AccountAdministration::class);

        $row = $accounts->createWithProvisionalPassword('t1', 'Ines', 'Mbarga', null, 'ines@ecole.test', 'fr', $actor);
        self::assertSame(12, strlen($row['provisional_password']));
        self::assertTrue($row['must_change_password']);
        self::assertSame(200, $this->login('ines@ecole.test', $row['provisional_password'])->getStatusCode());

        $again = $accounts->reissueProvisionalPassword('t1', $row['user_id'], $actor, 'e1', true);
        self::assertNotSame($row['provisional_password'], $again['provisional_password']);
        self::assertSame(404, (function () use ($accounts, $actor, $row): int {
            try {
                $accounts->reissueProvisionalPassword('platform', $row['user_id'], $actor);
            } catch (\Scholaris\Core\Http\HttpException $e) {
                return $e->status;
            }

            return 0;
        })(), 'Jamais sur le client technique.');
    }
}
