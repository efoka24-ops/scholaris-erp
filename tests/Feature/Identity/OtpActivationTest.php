<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\Support\Identity\IdentityTestCase;

/** US1 : activation du compte par code à 6 chiffres (5 min, 3 essais, renvoi après 60 s) puis choix du mot de passe. */
final class OtpActivationTest extends IdentityTestCase
{
    private const PHONE = '+237691123456';

    protected function setUp(): void
    {
        $this->boot();
        $this->createUser('t1', 'Awa', self::PHONE, active: false);
    }

    /** @return array<string, mixed> */
    private function request(string $identifier = self::PHONE, array $extra = []): array
    {
        $response = $this->call('POST', '/api/v1/auth/activation/request', [], ['identifier' => $identifier] + $extra);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    private function verify(string $code, string $identifier = self::PHONE): \Psr\Http\Message\ResponseInterface
    {
        return $this->call('POST', '/api/v1/auth/activation/verify', [], ['identifier' => $identifier, 'code' => $code]);
    }

    public function testLaDemandeEnvoieUnCodeASixChiffresEtMasqueLeNumero(): void
    {
        $body = $this->request();

        self::assertSame('sms', $body['channel']);
        self::assertSame('+237 6•• •• 34 56', $body['masked_to']);
        self::assertSame(300, $body['expires_in']);
        self::assertSame(60, $body['resend_in']);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $this->sms->lastCode(self::PHONE));
    }

    public function testActivationCompleteMetUnMotDePasseEtPermetLaConnexion(): void
    {
        $this->request();
        $code = (string) $this->sms->lastCode(self::PHONE);

        $verified = $this->verify($code);
        self::assertSame(200, $verified->getStatusCode());
        $ticket = (string) $this->json($verified)['ticket'];

        $password = $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => 'Mangue-Verte-Soleil-77']);
        self::assertSame(200, $password->getStatusCode(), (string) $password->getBody());

        $login = $this->login(self::PHONE, 'Mangue-Verte-Soleil-77');
        self::assertSame(200, $login->getStatusCode());
    }

    public function testLeTicketNeSertQuUneFois(): void
    {
        $this->request();
        $ticket = (string) $this->json($this->verify((string) $this->sms->lastCode(self::PHONE)))['ticket'];
        $first = $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => 'Mangue-Verte-Soleil-77']);
        $second = $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => 'Autre-Phrase-Longue-88']);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(422, $second->getStatusCode());
    }

    public function testLeCodeExpireApresCinqMinutes(): void
    {
        $this->request();
        $code = (string) $this->sms->lastCode(self::PHONE);
        $this->clock->advance(301);

        $response = $this->verify($code);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Ce code a expiré. Demandez-en un nouveau.', $this->json($response)['detail']);
    }

    public function testTroisEssaisMaximumPuisLeBonCodeEstRefuse(): void
    {
        $this->request();
        $code = (string) $this->sms->lastCode(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        $first = $this->json($this->verify($wrong));
        $second = $this->json($this->verify($wrong));
        $third = $this->verify($wrong);
        $afterwards = $this->verify($code);

        self::assertSame(2, $first['attempts_left']);
        self::assertSame('Code incorrect. Il vous reste 2 essais.', $first['detail']);
        self::assertSame(1, $second['attempts_left']);
        self::assertSame(422, $third->getStatusCode());
        self::assertSame(0, $this->json($third)['attempts_left']);
        self::assertSame(422, $afterwards->getStatusCode(), 'Le bon code ne doit plus être accepté après trois échecs.');
    }

    public function testLeRenvoiEstRefuseAvantSoixanteSecondesPuisAccepte(): void
    {
        $this->request();

        $tooSoon = $this->call('POST', '/api/v1/auth/activation/request', [], ['identifier' => self::PHONE]);
        self::assertSame(429, $tooSoon->getStatusCode());
        self::assertNotSame('', $tooSoon->getHeaderLine('Retry-After'));

        $this->clock->advance(61);
        $this->request();
        self::assertCount(2, $this->sms->sent);
    }

    public function testUnNouveauCodeInvalideLePrecedent(): void
    {
        $this->request();
        $old = (string) $this->sms->lastCode(self::PHONE);
        $this->clock->advance(61);
        $this->request();
        $new = (string) $this->sms->lastCode(self::PHONE);

        if ($old !== $new) {
            self::assertSame(422, $this->verify($old)->getStatusCode());
        }
        self::assertSame(200, $this->verify($new)->getStatusCode());
    }

    public function testLeCodePeutEtreEnvoyeParEmailSiUneAdresseExiste(): void
    {
        $this->createUser('t1', 'Paul', '+237677000111', email: 'paul@exemple.cm', active: false);

        $response = $this->call('POST', '/api/v1/auth/activation/request', [], ['identifier' => '+237677000111', 'channel' => 'email']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('email', $this->json($response)['channel']);
        self::assertSame('p•••@exemple.cm', $this->json($response)['masked_to']);
        self::assertNotNull($this->mail->lastCode('paul@exemple.cm'));
    }

    public function testMotsDePasseFaiblesRefuses(): void
    {
        foreach (['court1', 'password123', 'azerty12345', '1234567890'] as $weak) {
            $this->clock->advance(61);
            $this->request();
            $ticket = (string) $this->json($this->verify((string) $this->sms->lastCode(self::PHONE)))['ticket'];

            $response = $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => $weak]);

            self::assertSame(422, $response->getStatusCode(), $weak . ' aurait dû être refusé.');
            self::assertArrayHasKey('password', $this->json($response)['errors']);
        }
    }

    public function testMotDePasseContenantLeNumeroRefuse(): void
    {
        $this->request();
        $ticket = (string) $this->json($this->verify((string) $this->sms->lastCode(self::PHONE)))['ticket'];

        $response = $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => 'abc691123456xyz']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testReponseIdentiquePourUnIdentifiantInconnu(): void
    {
        $known = $this->request();
        $unknown = $this->request('+237699999999');

        self::assertSame(array_keys($known), array_keys($unknown));
        self::assertSame($known['expires_in'], $unknown['expires_in']);
        self::assertSame($known['resend_in'], $unknown['resend_in']);
        self::assertSame('+237 6•• •• 99 99', $unknown['masked_to']);
        self::assertSame([], $this->sms->messagesTo('+237699999999'));

        $wrongKnown = $this->json($this->verify('000000'));
        $wrongUnknown = $this->json($this->verify('000000', '+237699999999'));
        self::assertSame($wrongKnown['detail'], $wrongUnknown['detail']);
        self::assertSame($wrongKnown['attempts_left'], $wrongUnknown['attempts_left']);
    }

    public function testIdentifiantInvalideRenvoie422(): void
    {
        $response = $this->call('POST', '/api/v1/auth/activation/request', [], ['identifier' => 'pas un identifiant']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testMotDePasseOublieReinitialiseEtRevoqueLesSessions(): void
    {
        $this->createUser('t1', 'Luc', '+237655000222');
        $token = $this->token('+237655000222');

        $this->call('POST', '/api/v1/auth/password/forgot', [], ['identifier' => '+237655000222']);
        $ticket = (string) $this->json($this->verify((string) $this->sms->lastCode('+237655000222'), '+237655000222'))['ticket'];
        $reset = $this->call('POST', '/api/v1/auth/activation/password', [], ['ticket' => $ticket, 'password' => 'Nouvelle-Phrase-Secrete-5']);

        self::assertSame(200, $reset->getStatusCode(), (string) $reset->getBody());
        self::assertSame(401, $this->call('GET', '/api/v1/me', $this->auth($token))->getStatusCode(), 'L\'ancienne session doit être fermée.');
        self::assertSame(200, $this->login('+237655000222', 'Nouvelle-Phrase-Secrete-5')->getStatusCode());
        self::assertSame(401, $this->login('+237655000222')->getStatusCode());
    }
}
