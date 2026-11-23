<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\Support\Identity\IdentityTestCase;

/** Connexion de l'administrateur de la plateforme par application d'authentification : identifiant + code, sans mot de passe. */
final class AuthenticatorLoginTest extends IdentityTestCase
{
    private const ADMIN = 'plateforme@scholaris.test';
    private const DIRECTOR = '+237691000020';

    protected function setUp(): void
    {
        $this->boot();
        $this->directory->add('t1', 'e1', 'Lycée Alpha', 'secondary');
        $this->createUser('platform', 'Plato', '+237699000001', [['platform_admin', 'tenant', 'platform']], self::ADMIN);
        $this->createUser('t1', 'Diane', self::DIRECTOR, [['directeur', 'establishment', 'e1']], 'diane@lycee.test');
    }

    private function codeLogin(string $identifier, string $code): \Psr\Http\Message\ResponseInterface
    {
        return $this->call('POST', '/api/v1/auth/login-authenticator', ['User-Agent' => 'Mozilla/5.0 Chrome/120.0'], ['identifier' => $identifier, 'code' => $code]);
    }

    /** Premier accès par mot de passe, enrôlement par QR code, puis déconnexion : renvoie le secret de l'application. */
    private function enroll(string $identifier): string
    {
        $token = (string) $this->json($this->login($identifier))['token'];
        $setup = $this->json($this->call('POST', '/api/v1/auth/2fa/setup', $this->auth($token)));
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string) $setup['qr_data_uri'], 'QR code à scanner au lieu de recopier la clé.');
        self::assertStringStartsWith('otpauth://totp/', (string) $setup['otpauth_uri']);
        $confirm = $this->call('POST', '/api/v1/auth/2fa/confirm', $this->auth($token), ['code' => $this->totp((string) $setup['secret'])]);
        self::assertSame(200, $confirm->getStatusCode(), (string) $confirm->getBody());

        return (string) $setup['secret'];
    }

    public function testAvantLEnrolementLeCodeNeSuffitPas(): void
    {
        self::assertSame(401, $this->codeLogin(self::ADMIN, '123456')->getStatusCode());
    }

    public function testApresLEnrolementLIdentifiantEtLeCodeOuvrentUneSessionVerifiee(): void
    {
        $secret = $this->enroll(self::ADMIN);
        $this->clock->advance(31); // pas TOTP suivant : le code d'enrôlement est déjà consommé

        $response = $this->codeLogin(self::ADMIN, $this->totp($secret));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response);
        self::assertFalse($data['two_factor_required'], 'Le code est déjà le second facteur.');
        self::assertFalse($data['two_factor_setup_required']);

        // Session utilisable tout de suite (pas de seconde saisie de code).
        self::assertSame(200, $this->call('GET', '/api/v1/me', $this->auth((string) $data['token']))->getStatusCode());
        self::assertSame(200, $this->call('GET', '/api/v1/platform/dashboard', $this->auth((string) $data['token']))->getStatusCode());
    }

    public function testUnCodeNeServantQuUneFois(): void
    {
        $secret = $this->enroll(self::ADMIN);
        $this->clock->advance(31);
        $code = $this->totp($secret);

        self::assertSame(200, $this->codeLogin(self::ADMIN, $code)->getStatusCode());
        self::assertSame(401, $this->codeLogin(self::ADMIN, $code)->getStatusCode(), 'Rejeu du même code refusé.');
    }

    public function testCodeFauxEtVerrouillageApresCinqEchecs(): void
    {
        $this->enroll(self::ADMIN);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(401, $this->codeLogin(self::ADMIN, '000000')->getStatusCode());
        }
        self::assertSame(423, $this->codeLogin(self::ADMIN, '000000')->getStatusCode());
    }

    public function testLesComptesDesClientsNeSeConnectentPasParCode(): void
    {
        $secret = $this->enroll(self::DIRECTOR);
        $this->clock->advance(31);

        self::assertSame(401, $this->codeLogin(self::DIRECTOR, $this->totp($secret))->getStatusCode(), 'Réservé à l\'administrateur de plateforme.');
    }

    public function testCodeMalFormeRefuse(): void
    {
        self::assertSame(422, $this->codeLogin(self::ADMIN, 'abc')->getStatusCode());
    }
}
