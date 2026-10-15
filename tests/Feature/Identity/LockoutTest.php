<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\Support\Identity\IdentityTestCase;

/** US1 : verrouillage de 15 minutes après 5 échecs, alerte au titulaire, aucune fuite entre « inconnu » et « faux ». */
final class LockoutTest extends IdentityTestCase
{
    private const PHONE = '+237691000001';

    protected function setUp(): void
    {
        $this->boot();
        $this->createUser('t1', 'Awa', self::PHONE, email: 'awa@exemple.cm');
    }

    public function testLeMessageIndiqueLesEssaisRestants(): void
    {
        $this->login(self::PHONE, 'faux-1');
        $this->login(self::PHONE, 'faux-2');
        $third = $this->login(self::PHONE, 'faux-3');

        self::assertSame(401, $third->getStatusCode());
        $body = $this->json($third);
        self::assertSame(2, $body['attempts_left']);
        self::assertSame('Mot de passe incorrect. Il vous reste 2 essais avant un verrouillage de 15 minutes.', $body['detail']);
        self::assertSame('application/problem+json; charset=utf-8', $third->getHeaderLine('Content-Type'));
        self::assertNotSame('', (string) $body['trace_id']);
    }

    public function testCinqEchecsVerrouillentLeCompteQuinzeMinutesEtAlertentLeTitulaire(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(401, $this->login(self::PHONE, 'faux-' . $i)->getStatusCode());
        }
        $fifth = $this->login(self::PHONE, 'faux-5');

        self::assertSame(423, $fifth->getStatusCode());
        $body = $this->json($fifth);
        self::assertSame(0, $body['attempts_left']);
        self::assertArrayHasKey('locked_until', $body);
        self::assertSame('900', $fifth->getHeaderLine('Retry-After'));
        self::assertNotEmpty($this->sms->messagesTo(self::PHONE), 'Le titulaire doit être alerté par SMS.');
        self::assertCount(1, array_filter($this->mail->sent, static fn (array $m): bool => $m['to'] === 'awa@exemple.cm'));

        // Même avec le bon mot de passe, le compte reste verrouillé.
        $good = $this->login(self::PHONE);
        self::assertSame(423, $good->getStatusCode());
        self::assertGreaterThan(0, (int) $good->getHeaderLine('Retry-After'));
    }

    public function testLeVerrouillageSeLeveApresQuinzeMinutes(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->login(self::PHONE, 'faux');
        }
        $this->clock->advance(901);

        self::assertSame(200, $this->login(self::PHONE)->getStatusCode());
    }

    public function testUneConnexionReussieRemetLeCompteurAZero(): void
    {
        $this->login(self::PHONE, 'faux');
        $this->login(self::PHONE, 'faux');
        self::assertSame(200, $this->login(self::PHONE)->getStatusCode());

        self::assertSame(4, $this->json($this->login(self::PHONE, 'faux'))['attempts_left']);
    }

    public function testAucuneFuiteEntreIdentifiantInconnuEtMotDePasseFaux(): void
    {
        $known = $this->login(self::PHONE, 'faux');
        $unknown = $this->login('+237699888777', 'faux');

        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $a = $this->json($known);
        $b = $this->json($unknown);
        self::assertSame($a['detail'], $b['detail']);
        self::assertSame($a['title'], $b['title']);
        self::assertSame($a['attempts_left'], $b['attempts_left']);

        // L'identifiant inconnu se verrouille exactement comme le connu.
        for ($i = 0; $i < 3; $i++) {
            $this->login('+237699888777', 'faux');
        }
        self::assertSame(423, $this->login('+237699888777', 'faux')->getStatusCode());
    }

    public function testCompteNonActiveNeSeDistinguePasDUnIdentifiantInconnu(): void
    {
        $this->createUser('t1', 'Neo', '+237691000009', active: false);

        $pending = $this->json($this->login('+237691000009', 'peu-importe'));
        $unknown = $this->json($this->login('+237691000008', 'peu-importe'));

        self::assertSame($unknown['detail'], $pending['detail']);
    }

    public function testNouvelAppareilEstSignaleEtAlerteParSmsEtEmail(): void
    {
        $first = $this->json($this->login(self::PHONE));
        self::assertTrue($first['new_device']);
        $before = count($this->sms->sent);

        $same = $this->json($this->login(self::PHONE));
        self::assertFalse($same['new_device']);
        self::assertCount($before, $this->sms->sent);

        $other = $this->json($this->login(self::PHONE, userAgent: 'Mozilla/5.0 (Linux; Android 14) Chrome/120.0 Mobile'));
        self::assertTrue($other['new_device']);
        $alert = end($this->sms->sent);
        self::assertStringContainsString('Chrome sur Android', $alert['message']);
        self::assertStringContainsString('Ce n\'est pas moi', $alert['message']);
        self::assertNotEmpty(array_filter($this->mail->sent, static fn (array $m): bool => $m['subject'] === 'Nouvel appareil détecté'));
    }
}
