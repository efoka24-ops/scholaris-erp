<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\EmailLayout;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpException;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpMailGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Mail\SmtpSettings;
use Tests\Support\Integrations\FakeSmtpScript;
use Tests\Support\Integrations\FakeSmtpStream;
use Tests\Support\Messaging\ArrayLogger;

/** Passerelle SMTP face à un faux serveur dans le processus de test (flux factice) puis sur la boucle locale. */
final class SmtpMailGatewayTest extends TestCase
{
    private const SECRET = 'p@ss-w0rd';

    private FakeSmtpScript $server;
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->server = new FakeSmtpScript();
        $this->logger = new ArrayLogger();
        FakeSmtpStream::register($this->server);
    }

    private function gateway(string $encryption = 'none', string $user = 'noreply@example.test', string $host = 'localhost', string $fromName = 'SCHOLARIS'): SmtpMailGateway
    {
        $settings = new SmtpSettings($host, 25, $encryption, $user, self::SECRET, 'noreply@example.test', $fromName, 5);

        return new SmtpMailGateway($settings, $this->logger, static fn (): mixed => FakeSmtpStream::open());
    }

    public function testEnvoiNominalAvecAuthentificationPlain(): void
    {
        $reference = $this->gateway()->send('parent@example.test', 'Reçu de paiement', "Bonjour,\nMerci pour votre paiement de 35000 FCFA.");

        self::assertMatchesRegularExpression('/^<[0-9a-z]{26}@example\.test>$/', $reference);
        self::assertSame([
            'EHLO scholaris.local', 'AUTH PLAIN <redacted>', 'MAIL FROM:<noreply@example.test>', 'RCPT TO:<parent@example.test>', 'DATA', 'QUIT',
        ], $this->server->commands);
        self::assertTrue($this->server->authenticated);

        $data = (string) $this->server->data;
        [$headers, $body] = explode("\r\n\r\n", $data, 2);
        self::assertStringContainsString('To: <parent@example.test>', $headers);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Reçu de paiement') . '?=', $headers);
        self::assertStringContainsString('Message-ID: ' . $reference, $headers);
        self::assertMatchesRegularExpression('/Content-Type: multipart\/alternative; boundary="(scholaris-[0-9a-f]{24})"/', $headers);
        $boundary = preg_match('/boundary="([^"]+)"/', $headers, $b) === 1 ? $b[1] : 'absent';
        $parts = array_values(array_filter(array_map('trim', explode('--' . $boundary, $body)), static fn (string $p): bool => $p !== '' && $p !== '--'));
        self::assertCount(2, $parts);
        [$textHeaders, $text] = explode("\r\n\r\n", $parts[0], 2);
        [$htmlHeaders, $html] = explode("\r\n\r\n", $parts[1], 2);
        self::assertStringContainsString('text/plain; charset=UTF-8', $textHeaders);
        self::assertSame("Bonjour,\nMerci pour votre paiement de 35000 FCFA.", base64_decode($text, true));
        self::assertStringContainsString('text/html; charset=UTF-8', $htmlHeaders);
        $decoded = (string) base64_decode($html, true);
        self::assertStringContainsString('Merci pour votre paiement de 35000 FCFA.', $decoded);
        self::assertStringContainsString('Reçu de paiement', $decoded);
    }

    public function testMiseEnPageHtmlEchappeLeTexteMetLeCodeEnEvidenceEtRendLesLiensCliquables(): void
    {
        $html = EmailLayout::render('Votre code', "SCHOLARIS : votre code est 482915. Il expire dans 5 minutes.\n\nOuvrez https://scholariserp.trugroup.cm/activation?t=a1. <script>alert(1)</script>", 'TRU GROUP');

        self::assertStringContainsString('letter-spacing:6px;color:#1a1a1a;">482915</td>', $html);
        self::assertStringContainsString('<a href="https://scholariserp.trugroup.cm/activation?t=a1" style="color:#f26b1d;">', $html);
        self::assertStringContainsString('Ouvrir / Open', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('envoyé par TRU GROUP', $html);
        self::assertStringNotContainsString('Ouvrir / Open', EmailLayout::render('Info', 'Sans lien ni code.'));
    }

    public function testAuthentificationLoginQuandPlainNEstPasOfferte(): void
    {
        $this->server->capabilities = ['AUTH LOGIN'];

        $this->gateway()->send('parent@example.test', 'Objet', 'Texte');

        self::assertTrue($this->server->authenticated);
        self::assertContains('AUTH LOGIN', $this->server->commands);
    }

    public function testMotDePasseJamaisJournaliseNiDansLesExceptions(): void
    {
        $this->server->password = 'autre-mot-de-passe';
        try {
            $this->gateway()->send('parent@example.test', 'Objet', 'Texte');
            self::fail('Identifiants refusés : une exception est attendue.');
        } catch (SmtpException $e) {
            self::assertSame(535, $e->smtpCode);
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString(base64_encode("\0noreply@example.test\0" . self::SECRET), $e->getMessage());
        }

    }

    public function testLeJournalNeContientNiMotDePasseNiContenu(): void
    {
        try {
            $this->server->password = 'autre';
            $this->gateway()->send('parent@example.test', 'Objet confidentiel', 'Corps confidentiel');
        } catch (SmtpException) {
        }
        $this->server->password = self::SECRET;
        $this->gateway()->send('parent@example.test', 'Objet confidentiel', 'Corps confidentiel');

        $dump = $this->logger->dump();
        self::assertNotSame([], $this->logger->lines);
        foreach ([self::SECRET, base64_encode(self::SECRET), 'confidentiel', 'parent@example.test'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $dump);
        }
    }

    public function testStarttlsExigeEtRefuseLeRepliEnClair(): void
    {
        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('STARTTLS');
        try {
            $this->gateway('tls', host: 'smtp.example.test')->send('parent@example.test', 'Objet', 'Texte');
        } finally {
            self::assertNotContains('AUTH PLAIN <redacted>', $this->server->commands, 'aucun identifiant ne part en clair');
            self::assertNull($this->server->data);
        }
    }

    public function testStarttlsAnnonceMaisNegociationImpossibleSurFluxFactice(): void
    {
        $this->server->capabilities = ['STARTTLS', 'AUTH PLAIN'];

        try {
            $this->gateway('tls', host: 'smtp.example.test')->send('parent@example.test', 'Objet', 'Texte');
            self::fail('La négociation TLS ne peut pas aboutir sur un flux factice.');
        } catch (SmtpException $e) {
            self::assertStringContainsString('TLS', $e->getMessage());
        }
        self::assertNotContains('AUTH PLAIN <redacted>', $this->server->commands);
        self::assertNull($this->server->data);
    }

    public function testAuthentificationEnClairRefuseHorsBoucleLocale(): void
    {
        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('sans chiffrement');

        $this->gateway('none', host: 'smtp.example.test')->send('parent@example.test', 'Objet', 'Texte');
    }

    public function testRefusDuDestinataire(): void
    {
        $this->server->rcptCode = 550;

        try {
            $this->gateway()->send('parent@example.test', 'Objet', 'Texte');
            self::fail('Destinataire refusé : une exception est attendue.');
        } catch (SmtpException $e) {
            self::assertSame(550, $e->smtpCode);
        }
        self::assertNull($this->server->data);
    }

    public function testRefusDuMessageApresDATA(): void
    {
        $this->server->dataCode = 554;

        $this->expectException(SmtpException::class);
        $this->gateway()->send('parent@example.test', 'Objet', 'Texte');
    }

    public function testDelaiDepasseQuandLeServeurResteMuet(): void
    {
        $this->server->silentAfterGreeting = true;

        $this->expectException(SmtpException::class);
        $this->gateway()->send('parent@example.test', 'Objet', 'Texte');
    }

    public function testInjectionDEnTetesRefusee(): void
    {
        foreach (["a@example.test\r\nBcc: x@example.test", 'a@example.test>', 'pas une adresse', ''] as $bad) {
            try {
                $this->gateway()->send($bad, 'Objet', 'Texte');
                self::fail('Adresse invalide acceptée : ' . json_encode($bad));
            } catch (SmtpException) {
                self::assertSame([], $this->server->commands, 'rien ne part vers le serveur');
            }
        }
    }

    public function testSujetEtNomExpediteurNePeuventPasInjecterDEnTetes(): void
    {
        $this->gateway(fromName: "SCHOLARIS\r\nBcc: x@example.test")->send('parent@example.test', "Objet\r\nBcc: x@example.test", 'Texte');

        $headers = explode("\r\n\r\n", (string) $this->server->data, 2)[0];
        self::assertStringNotContainsString("\r\nBcc:", $headers);
    }

    public function testSansAuthentificationQuandAucunIdentifiant(): void
    {
        $this->gateway(user: '')->send('parent@example.test', 'Objet', 'Texte');

        self::assertNotContains('AUTH LOGIN', $this->server->commands);
        self::assertFalse($this->server->authenticated);
    }

    public function testReglagesNeRevelentPasLeMotDePasse(): void
    {
        $settings = new SmtpSettings('h', 587, 'tls', 'u', self::SECRET, 'a@example.test', 'S');

        self::assertStringNotContainsString(self::SECRET, print_r($settings, true) . json_encode($settings->__debugInfo()));
    }

    public function testDialogueReelSurSocketDeBoucleLocale(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open indisponible.');
        }
        $transcript = tempnam(sys_get_temp_dir(), 'smtp');
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/Integrations/loopback_smtp_server.php', (string) $transcript],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $port = (int) trim((string) fgets($pipes[1]));
            self::assertGreaterThan(0, $port);

            $settings = new SmtpSettings('127.0.0.1', $port, 'none', 'noreply@example.test', self::SECRET, 'noreply@example.test', 'SCHOLARIS', 5);
            $reference = (new SmtpMailGateway($settings, $this->logger))->send('parent@example.test', 'Objet', 'Texte');
            proc_close($process);
            $process = null;

            $seen = json_decode((string) file_get_contents((string) $transcript), true);
            self::assertTrue($seen['authenticated']);
            self::assertSame('MAIL FROM:<noreply@example.test>', $seen['commands'][2]);
            self::assertStringContainsString('Message-ID: ' . $reference, (string) $seen['data']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
            }
            @unlink((string) $transcript);
        }
    }
}
