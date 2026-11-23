<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\Support\Identity\IdentityTestCase;

/** US1 : 2FA TOTP obligatoire pour les rôles sensibles, codes de secours, confiance 30 jours. */
final class TwoFactorTest extends IdentityTestCase
{
    private const DIRECTOR = '+237691000010';
    private const PARENT = '+237691000011';

    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Lycée Alpha', 'secondary');
        $this->createUser('t1', 'Diane', self::DIRECTOR, [['directeur', 'establishment', 'e1']]);
        $this->createUser('t1', 'Paul', self::PARENT, [['parent', 'establishment', 'e1']]);
    }

    /**
     * Connecte le directeur et active sa 2FA ; renvoie [jeton, secret, codes de secours].
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function enroll(): array
    {
        $login = $this->json($this->login(self::DIRECTOR));
        $token = (string) $login['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        $confirm = $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);
        self::assertSame(200, $confirm->getStatusCode(), (string) $confirm->getBody());

        return [$token, (string) $setup['secret'], $this->json($confirm)['backup_codes']];
    }

    public function testRoleNonSensibleNAPasDe2FA(): void
    {
        $login = $this->json($this->login(self::PARENT));

        self::assertFalse($login['two_factor_required']);
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth((string) $login['token']))->getStatusCode());
    }

    public function testRoleSensibleExigeLaConfigurationDeLa2FAAvantTouteRouteMetier(): void
    {
        $login = $this->json($this->login(self::DIRECTOR));

        self::assertTrue($login['two_factor_required']);
        self::assertTrue($login['two_factor_setup_required']);
        $token = (string) $login['token'];

        $blocked = $this->call('GET', '/api/v1/me/contexts', $this->auth($token));
        self::assertSame(403, $blocked->getStatusCode());
        self::assertStringEndsWith('two-factor-required', $this->json($blocked)['type']);
        self::assertSame(403, $this->call('GET', '/api/v1/role-assignments', $this->auth($token, 'e1'))->getStatusCode());
        // Le profil reste lisible pour afficher le bandeau « obligatoire pour votre rôle ».
        $me = $this->json($this->call('GET', '/api/v1/me', $this->auth($token)));
        self::assertTrue($me['data']['two_factor']['required']);
        self::assertFalse($me['data']['two_factor']['verified']);
    }

    public function testActivationTotpOuvreLaSessionEtDonneDixCodesDeSecours(): void
    {
        [$token, , $codes] = $this->enroll();

        self::assertCount(10, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        }
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth($token))->getStatusCode());
        // Jamais de secret ni de code de secours en clair en base.
        $stored = (string) $this->pdo()->query('SELECT group_concat(code_hash) FROM backup_codes')->fetchColumn();
        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $stored);
        }
    }

    public function testLeSecretTotpEstChiffreEnBase(): void
    {
        [, $secret] = $this->enroll();

        $stored = (string) $this->pdo()->query('SELECT two_factor_secret FROM users WHERE two_factor_secret IS NOT NULL')->fetchColumn();

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString($secret, $stored);
    }

    public function testNouvelleConnexionExigeLeCodeTotpPuisAutorise(): void
    {
        [, $secret] = $this->enroll();
        $this->clock->advance(60);

        $login = $this->json($this->login(self::DIRECTOR, userAgent: 'Firefox/121 Linux'));
        self::assertTrue($login['two_factor_required']);
        self::assertFalse($login['two_factor_setup_required']);
        $token = (string) $login['token'];
        self::assertSame(403, $this->call('GET', '/api/v1/me/contexts', $this->auth($token))->getStatusCode());

        $verify = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => $this->totp($secret)]);

        self::assertSame(200, $verify->getStatusCode(), (string) $verify->getBody());
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth($token))->getStatusCode());
    }

    public function testCodeIncorrectDecompteLesEssaisPuisFermeLaSession(): void
    {
        $this->enroll();
        $this->clock->advance(60);
        $token = (string) $this->json($this->login(self::DIRECTOR, userAgent: 'Firefox/121 Linux'))['token'];

        $first = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => '000000']);
        $second = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => '000000']);
        $third = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => '000000']);

        self::assertSame(422, $first->getStatusCode());
        self::assertSame('Code incorrect · 2 essais restants.', $this->json($first)['detail']);
        self::assertSame('Code incorrect · 1 essai restant.', $this->json($second)['detail']);
        self::assertSame(401, $third->getStatusCode());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($token))->getStatusCode());
    }

    public function testUnCodeTotpNeSertQuUneFois(): void
    {
        [, $secret] = $this->enroll();
        $this->clock->advance(90);
        $token = (string) $this->json($this->login(self::DIRECTOR, userAgent: 'Firefox/121 Linux'))['token'];
        $code = $this->totp($secret);
        $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => $code]);
        $other = (string) $this->json($this->login(self::DIRECTOR, userAgent: 'Safari/17 Macintosh'))['token'];

        $replay = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($other), ['code' => $code]);

        self::assertSame(422, $replay->getStatusCode());
    }

    public function testCodeDeSecoursAUsageUnique(): void
    {
        [, , $codes] = $this->enroll();
        $this->clock->advance(60);
        $token = (string) $this->json($this->login(self::DIRECTOR, userAgent: 'Firefox/121 Linux'))['token'];

        $use = $this->call('POST', '/api/v1/auth/2fa/recovery', $this->auth($token), ['code' => strtolower(substr($codes[0], 0, 4)) . '-' . substr($codes[0], 4)]);
        self::assertSame(200, $use->getStatusCode(), (string) $use->getBody());
        self::assertSame(9, $this->json($use)['backup_codes_left']);
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth($token))->getStatusCode());

        $other = (string) $this->json($this->login(self::DIRECTOR, userAgent: 'Safari/17 Macintosh'))['token'];
        $again = $this->call('POST', '/api/v1/auth/2fa/recovery', $this->auth($other), ['code' => $codes[0]]);
        self::assertSame(422, $again->getStatusCode());
    }

    public function testFaireConfianceALAppareilEvite2faPendantTrenteJours(): void
    {
        [$token, $secret] = $this->enroll();
        $this->clock->advance(60);
        $login = $this->json($this->login(self::DIRECTOR));
        $second = (string) $login['token'];
        $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($second), ['code' => $this->totp($secret), 'trust_device' => true]);

        $this->clock->advance(86400 * 10);
        $trusted = $this->json($this->login(self::DIRECTOR));
        self::assertFalse($trusted['two_factor_required']);
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth((string) $trusted['token']))->getStatusCode());

        $this->clock->advance(86400 * 25);
        $expired = $this->json($this->login(self::DIRECTOR));
        self::assertTrue($expired['two_factor_required']);
        self::assertNotSame('', $token);
    }

    public function testCodeParSmsEstUneAlternative(): void
    {
        $this->enroll();
        $this->clock->advance(60);
        $token = (string) $this->json($this->login(self::DIRECTOR, userAgent: 'Firefox/121 Linux'))['token'];

        $sent = $this->call('POST', '/api/v1/auth/2fa/sms', $this->auth($token));
        self::assertSame(200, $sent->getStatusCode());
        self::assertSame(429, $this->call('POST', '/api/v1/auth/2fa/sms', $this->auth($token))->getStatusCode());
        $code = (string) $this->sms->lastCode(self::DIRECTOR);

        $verify = $this->call('POST', '/api/v1/auth/2fa/verify', $this->auth($token), ['code' => $code, 'method' => 'sms']);

        self::assertSame(200, $verify->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/v1/me/contexts', $this->auth($token))->getStatusCode());
    }
}
