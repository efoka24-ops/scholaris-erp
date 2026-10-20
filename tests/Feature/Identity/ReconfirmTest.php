<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Identity\Application\Moment;
use Scholaris\Modules\Socle\Identity\Application\TwoFactorPolicy;
use Scholaris\Modules\Socle\Identity\Contracts\RecentSecondFactor;
use Tests\Support\Identity\IdentityTestCase;

/**
 * C-001 : la « 2FA récente » des actions sensibles devient une « confirmation récente » : 2FA récente si la personne en a
 * une, sinon mot de passe saisi dans les 10 dernières minutes (connexion ou POST /auth/reconfirm). L'administrateur de
 * plateforme garde la double authentification seule.
 */
final class ReconfirmTest extends IdentityTestCase
{
    private const STAFF = 'chef@ecole.test';
    private const ADMIN = 'plateforme@scholaris.test';

    protected function setUp(): void
    {
        $this->boot([TwoFactorPolicy::class => new TwoFactorPolicy(Config::fromArray(['app' => ['two_factor_required' => false]]))]);
        $this->directory->add('t1', 'e1', 'Collège Alpha', 'secondary');
        $this->createUser('t1', 'Chef', '+237691000010', [['directeur', 'establishment', 'e1']], self::STAFF);
        $this->createUser('platform', 'Plato', '+237699000001', [['platform_admin', 'tenant', 'platform']], self::ADMIN);
    }

    private function recent(string $login, int $seconds = 600): bool
    {
        $row = $this->rows('SELECT id, tenant_id FROM users WHERE email = ?', [$login])[0];

        return $this->kernel->container()->get(RecentSecondFactor::class)->hasRecent((string) $row['tenant_id'], (string) $row['id'], $seconds);
    }

    public function testLaSaisieDuMotDePasseALaConnexionVautConfirmationPendantDixMinutes(): void
    {
        self::assertFalse($this->recent(self::STAFF), 'Aucune session : aucune preuve.');
        $this->token(self::STAFF);

        self::assertTrue($this->recent(self::STAFF));
        $this->clock->advance(599);
        self::assertTrue($this->recent(self::STAFF), 'Accepté en deçà de 10 minutes.');
        $this->clock->advance(2);
        self::assertFalse($this->recent(self::STAFF), 'Refusé au-delà de 10 minutes.');
    }

    public function testReconfirmerParMotDePasseRenouvelleLaPreuve(): void
    {
        $token = $this->token(self::STAFF);
        $this->clock->advance(700);
        self::assertFalse($this->recent(self::STAFF));

        $wrong = $this->call('POST', '/api/v1/auth/reconfirm', $this->auth($token), ['password' => 'Faux-Faux-Faux-1']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame(4, $this->json($wrong)['attempts_left']);
        self::assertFalse($this->recent(self::STAFF));

        $ok = $this->call('POST', '/api/v1/auth/reconfirm', $this->auth($token), ['password' => self::PASSWORD]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertTrue($this->json($ok)['confirmed']);
        self::assertSame(600, $this->json($ok)['valid_for']);
        self::assertTrue($this->recent(self::STAFF));
        $this->clock->advance(601);
        self::assertFalse($this->recent(self::STAFF));
        self::assertSame('identity.reconfirmed', (string) $this->scalar('SELECT action FROM audit_log WHERE action = ?', ['identity.reconfirmed']));
    }

    public function testCinqEchecsVerrouillentLaConfirmationEtLaConnexion(): void
    {
        $token = $this->token(self::STAFF);
        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->call('POST', '/api/v1/auth/reconfirm', $this->auth($token), ['password' => 'Faux-Faux-Faux-1'])->getStatusCode();
        }
        self::assertSame([422, 422, 422, 422, 423], $statuses);
        self::assertSame(423, $this->call('POST', '/api/v1/auth/reconfirm', $this->auth($token), ['password' => self::PASSWORD])->getStatusCode(), 'Même le bon mot de passe est refusé pendant le verrouillage.');
        self::assertSame(423, $this->login(self::STAFF)->getStatusCode());
    }

    public function testUnePersonneAvecDoubleAuthentificationConfirmeParSonCodeSeulement(): void
    {
        $token = $this->token(self::STAFF);
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $confirm = $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);
        self::assertSame(200, $confirm->getStatusCode(), (string) $confirm->getBody());
        self::assertTrue($this->recent(self::STAFF), 'La 2FA vient d\'être validée.');

        $this->clock->advance(700);
        self::assertFalse($this->recent(self::STAFF), 'Le mot de passe de la connexion ne suffit plus pour qui a une 2FA.');
        $reconfirm = $this->call('POST', '/api/v1/auth/reconfirm', $this->auth($token), ['password' => self::PASSWORD]);
        self::assertSame(409, $reconfirm->getStatusCode());
        self::assertStringEndsWith('second-factor-required', $this->json($reconfirm)['type']);
        self::assertFalse($this->recent(self::STAFF));

        $this->clock->advance(31);
        $verify = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => $this->totp((string) $setup['secret']), 'method' => 'totp']);
        self::assertSame(200, $verify->getStatusCode(), (string) $verify->getBody());
        self::assertTrue($this->recent(self::STAFF));
    }

    public function testLAdministrateurDePlateformeNAJamaisLeMotDePasse(): void
    {
        $token = $this->token(self::ADMIN);
        self::assertFalse($this->recent(self::ADMIN), 'Le mot de passe ne prouve rien pour l\'administrateur de plateforme.');

        $reconfirm = $this->call('POST', '/api/v1/auth/reconfirm', $this->auth($token), ['password' => self::PASSWORD]);
        // Sa session est en attente de 2FA : la route reste fermée (et le service la refuserait de toute façon, voir PasswordService).
        self::assertSame(403, $reconfirm->getStatusCode());
        self::assertStringEndsWith('two-factor-required', $this->json($reconfirm)['type']);
        self::assertFalse($this->recent(self::ADMIN));
    }

    public function testLaPreuveNEstPasCelleDUnAutreClientNiDUneSessionRevoquee(): void
    {
        $token = $this->token(self::STAFF);
        $id = $this->userIdOf(self::STAFF);
        $second = $this->kernel->container()->get(RecentSecondFactor::class);
        self::assertTrue($second->hasRecent('t1', $id, 600));
        self::assertFalse($second->hasRecent('t2', $id, 600), 'Autre client.');

        $this->call('POST', '/api/v1/auth/logout', $this->auth($token));
        self::assertFalse($second->hasRecent('t1', $id, 600), 'Session fermée : plus de preuve.');
        self::assertNotSame('', $this->kernel->container()->get(Moment::class)->iso());
    }
}
