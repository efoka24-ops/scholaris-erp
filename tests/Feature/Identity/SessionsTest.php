<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Identity\Application\ServiceAccountService;
use Tests\Support\Identity\IdentityTestCase;

/** Jetons hachés, appareils, déconnexion partout, « Ce n'est pas moi », comptes de service. */
final class SessionsTest extends IdentityTestCase
{
    private const PHONE = '+237691000040';

    protected function setUp(): void
    {
        $this->boot();
        $this->createUser('t1', 'Awa', self::PHONE);
    }

    public function testLeJetonBrutNApparaitJamaisEnBase(): void
    {
        $token = $this->token(self::PHONE);

        $dump = '';
        foreach (['access_tokens', 'users', 'devices', 'one_time_codes', 'login_attempts', 'audit_log'] as $table) {
            foreach ($this->pdo()->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
                $dump .= json_encode($row, JSON_UNESCAPED_UNICODE);
            }
        }
        self::assertStringNotContainsString($token, $dump);
        self::assertStringNotContainsString(self::PASSWORD, $dump);
        self::assertSame(hash('sha256', $token), $this->pdo()->query('SELECT token_hash FROM access_tokens')->fetchColumn());
        self::assertStringStartsWith('$argon2id$', (string) $this->pdo()->query('SELECT password_hash FROM users')->fetchColumn());
    }

    public function testLeJetonExpireEtLOptionRememberLAllonge(): void
    {
        $short = $this->token(self::PHONE);
        $long = (string) $this->json($this->login(self::PHONE, extra: ['remember' => true]))['token'];

        $this->clock->advance(9 * 3600);

        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($short))->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/v1/me', $this->auth($long))->getStatusCode());
        $this->clock->advance(31 * 86400);
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($long))->getStatusCode());
    }

    public function testDeconnexionRevoqueLeJeton(): void
    {
        $token = $this->token(self::PHONE);

        self::assertSame(204, $this->call('POST', '/api/v1/auth/logout', $this->auth($token))->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($token))->getStatusCode());
    }

    public function testListeEtRevocationDUnAppareil(): void
    {
        $desktop = $this->token(self::PHONE);
        $mobile = (string) $this->json($this->login(self::PHONE, userAgent: 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile'))['token'];

        $list = $this->json($this->call('GET', '/api/v1/me/devices', $this->auth($desktop)))['data'];
        self::assertCount(2, $list);
        $current = array_values(array_filter($list, static fn (array $d): bool => $d['current']));
        self::assertCount(1, $current);
        self::assertSame('Chrome sur Windows', $current[0]['name']);
        $other = array_values(array_filter($list, static fn (array $d): bool => !$d['current']))[0];

        self::assertSame(204, $this->call('DELETE', '/api/v1/me/devices/' . $other['id'], $this->auth($desktop))->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($mobile))->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/v1/me', $this->auth($desktop))->getStatusCode());
        self::assertSame(404, $this->call('DELETE', '/api/v1/me/devices/' . $other['id'], $this->auth($desktop))->getStatusCode());
    }

    public function testUnAppareilDUnAutreUtilisateurEstIntrouvable(): void
    {
        $this->createUser('t1', 'Bob', '+237691000041');
        $bob = $this->token('+237691000041');
        $bobDevice = (string) $this->json($this->call('GET', '/api/v1/me/devices', $this->auth($bob)))['data'][0]['id'];
        $awa = $this->token(self::PHONE);

        self::assertSame(404, $this->call('DELETE', '/api/v1/me/devices/' . $bobDevice, $this->auth($awa))->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/v1/me', $this->auth($bob))->getStatusCode());
    }

    public function testDeconnecterPartoutRevoqueTousLesAppareils(): void
    {
        $tokens = [
            $this->token(self::PHONE),
            (string) $this->json($this->login(self::PHONE, userAgent: 'Firefox/121 Linux'))['token'],
            (string) $this->json($this->login(self::PHONE, userAgent: 'Safari/17 Macintosh', extra: ['remember' => true]))['token'],
        ];

        self::assertSame(204, $this->call('DELETE', '/api/v1/me/sessions', $this->auth($tokens[0]))->getStatusCode());

        foreach ($tokens as $token) {
            self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($token))->getStatusCode());
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM access_tokens WHERE revoked_at IS NULL')->fetchColumn());
    }

    public function testCeNEstPasMoiBloqueLeCompteEtRevoqueTout(): void
    {
        $a = $this->token(self::PHONE);
        $b = (string) $this->json($this->login(self::PHONE, userAgent: 'Firefox/121 Linux'))['token'];

        $response = $this->call('POST', '/api/v1/auth/not-me', $this->auth($a));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($a))->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($b))->getStatusCode());
        $login = $this->login(self::PHONE);
        self::assertSame(423, $login->getStatusCode());
        self::assertStringEndsWith('account-locked', $this->json($login)['type']);

        // Seule une réinitialisation par code rouvre le compte.
        $this->call('POST', '/api/v1/auth/password/forgot', [], ['identifier' => self::PHONE]);
        $ticket = (string) $this->json($this->call('POST', '/api/v1/auth/activation/verify', [], ['identifier' => self::PHONE, 'code' => (string) $this->sms->lastCode(self::PHONE)]))['ticket'];
        $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => 'Nouvelle-Phrase-Secrete-5']);
        self::assertSame(200, $this->login(self::PHONE, 'Nouvelle-Phrase-Secrete-5')->getStatusCode());
    }

    public function testLeCookieWebEstHttpOnlyEtProtegeContreLeCsrf(): void
    {
        $response = $this->login(self::PHONE, extra: ['client' => 'web']);
        $cookie = $response->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertArrayNotHasKey('token', $this->json($response));

        preg_match('/scholaris_token=([^;]+)/', $cookie, $m);
        $request = static fn (string $method, string $path, array $headers): \Nyholm\Psr7\ServerRequest => (new \Nyholm\Psr7\ServerRequest($method, $path, $headers))->withCookieParams(['scholaris_token' => $m[1]]);

        self::assertSame(200, $this->kernel->handle($request('GET', '/api/v1/me', []))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($request('POST', '/api/v1/auth/logout', []))->getStatusCode());
        self::assertSame(204, $this->kernel->handle($request('POST', '/api/v1/auth/logout', ['X-Requested-With' => 'XMLHttpRequest']))->getStatusCode());
    }

    public function testCompteDeServiceCleHacheeRotationEtPermissions(): void
    {
        $service = $this->kernel->container()->get(ServiceAccountService::class);
        $created = $service->create('t1', 'Synchro comptable', ['finance.read']);

        self::assertNotNull($service->authenticate($created['key']));
        self::assertSame('', (string) $this->pdo()->query("SELECT 1 FROM service_accounts WHERE key_hash = '" . $created['key'] . "'")->fetchColumn());

        $newKey = $service->rotate('t1', $created['id']);
        self::assertNotSame($created['key'], $newKey);
        self::assertNotNull($service->authenticate($newKey));
        self::assertNotNull($service->authenticate($created['key']), 'L\'ancienne clé reste valable pendant la période de grâce.');
        $this->clock->advance(86401);
        self::assertNull($service->authenticate($created['key']));

        $checker = $this->kernel->container()->get(PermissionChecker::class);
        $principal = new Principal('svc:' . $created['id'], 't1');
        self::assertTrue($checker->can($principal, 'finance.read'));
        self::assertFalse($checker->can($principal, 'finance.manage'));

        $response = $this->call('GET', '/api/v1/me/todo', $this->auth($newKey));
        self::assertSame(403, $response->getStatusCode(), 'Un compte de service n\'a pas de session utilisateur.');

        $service->revoke('t1', $created['id']);
        self::assertNull($service->authenticate($newKey));
    }
}
