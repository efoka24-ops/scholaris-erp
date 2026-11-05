<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Integrations\Console\SmsCheckCommand;
use Scholaris\Modules\Socle\Integrations\Contracts\SmsStatusLookup;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpException;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\TeeeOtpSmsGateway;
use Tests\Support\Messaging\ArrayLogger;

/** Adaptateur SMS TeeeOTP, testé avec un transport simulé (aucun appel réseau). Clé et numéros fictifs. */
final class TeeeOtpSmsGatewayTest extends TestCase
{
    private const KEY = 'sk_test_cle_fictive_0123456789';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}> */
    private array $calls = [];
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
    }

    /** @param array{status: int, body: string}|\Throwable $response */
    private function client(array|\Throwable $response, string $key = self::KEY): TeeeOtpClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use ($response): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        };

        return new TeeeOtpClient('https://teeeotp.example.test/api/', $key, 7, $transport);
    }

    /** @param array{status: int, body: string}|\Throwable $response */
    private function gateway(array|\Throwable $response = ['status' => 202, 'body' => '{"id":"sms_42","status":"queued"}']): TeeeOtpSmsGateway
    {
        return new TeeeOtpSmsGateway($this->client($response), $this->logger);
    }

    public function testEnvoiConstruitLaRequeteEtRenvoieLIdentifiantCommeReference(): void
    {
        $reference = $this->gateway()->send('6 99 00 00 01', 'Reçu de paiement : 35000 FCFA');

        self::assertSame('teeeotp-sms_42', $reference);
        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://teeeotp.example.test/api/v1/sms', $call['url']);
        self::assertSame(7, $call['timeout']);
        self::assertSame(self::KEY, $call['headers']['X-Api-Key']);
        self::assertSame('application/json', $call['headers']['Content-Type']);
        self::assertSame(['phone' => '+237699000001', 'message' => 'Reçu de paiement : 35000 FCFA'], json_decode((string) $call['body'], true));
    }

    public function testNumerosNormalisesEtNumeroEtrangerRefuseSansAppel(): void
    {
        foreach (['699000001', '237699000001', '+237 699 00 00 01', '00237699000001'] as $raw) {
            self::assertSame('+237699000001', TeeeOtpSmsGateway::normalizePhone($raw), $raw);
        }
        foreach (['+33612345678', '12345', '', '+237 299 00 00 01'] as $raw) {
            self::assertNull(TeeeOtpSmsGateway::normalizePhone($raw), $raw);
        }

        try {
            $this->gateway()->send('+33612345678', 'Bonjour');
            self::fail('Numéro étranger accepté.');
        } catch (TeeeOtpException $e) {
            self::assertSame(TeeeOtpException::INVALID_PHONE, $e->errorCode);
        }
        self::assertSame([], $this->calls);
    }

    public function testMessageTronqueProprementA480Caracteres(): void
    {
        $long = str_repeat('élève absent aujourd\'hui ', 40);
        $this->gateway()->send('699000001', $long);

        $sent = (string) json_decode((string) $this->calls[0]['body'], true)['message'];
        self::assertLessThanOrEqual(480, mb_strlen($sent));
        self::assertStringEndsWith('…', $sent);
        self::assertStringNotContainsString(' …', $sent);
        self::assertStringStartsWith('élève absent aujourd\'hui élève', $sent);

        self::assertSame('court', TeeeOtpSmsGateway::truncate('  court '));
        self::assertSame(480, mb_strlen(TeeeOtpSmsGateway::truncate(str_repeat('é', 480))));
        self::assertSame(480, mb_strlen(TeeeOtpSmsGateway::truncate(str_repeat('é', 900))));
    }

    public function testMessageVideRefuse(): void
    {
        $this->expectException(TeeeOtpException::class);
        $this->gateway()->send('699000001', '   ');
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function errors(): array
    {
        return [
            'quota' => [402, '{"error":{"code":"QUOTA_EXCEEDED","message":"Quota"}}', TeeeOtpException::QUOTA_EXCEEDED],
            'trop de demandes' => [429, '{"error":{"code":"TOO_MANY_REQUESTS","message":"x","retryAfter":30}}', TeeeOtpException::TOO_MANY_REQUESTS],
            'numéro' => [422, '{"error":{"code":"INVALID_PHONE","message":"x"}}', TeeeOtpException::INVALID_PHONE],
            'clé refusée sans code' => [401, '{}', TeeeOtpException::UNAUTHORIZED],
            'clé refusée' => [401, '{"error":{"code":"INVALID_API_KEY","message":"x"}}', TeeeOtpException::UNAUTHORIZED],
            'corps illisible' => [502, '<html>Bad gateway</html>', TeeeOtpException::UNEXPECTED],
        ];
    }

    /** @dataProvider errors */
    public function testErreursTeeeOtpTraduitesEnExceptionsExplicites(int $status, string $body, string $code): void
    {
        try {
            $this->gateway(['status' => $status, 'body' => $body])->send('699000001', 'Bonjour');
            self::fail('Aucune exception.');
        } catch (TeeeOtpException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->httpStatus);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString('699000001', $e->getMessage());
        }
    }

    public function testServiceInjoignableEtQuotaSontTemporaires(): void
    {
        try {
            $this->gateway(new \RuntimeException('connexion ' . self::KEY))->send('699000001', 'Bonjour');
            self::fail('Aucune exception.');
        } catch (TeeeOtpException $e) {
            self::assertSame(TeeeOtpException::UNREACHABLE, $e->errorCode);
            self::assertTrue($e->isTemporary());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        self::assertFalse((new TeeeOtpException(TeeeOtpException::INVALID_PHONE, 422))->isTemporary());
        self::assertSame(30, (new TeeeOtpException(TeeeOtpException::TOO_MANY_REQUESTS, 429, 30))->retryAfter);
    }

    public function testReponseSansIdentifiantEstRefusee(): void
    {
        $this->expectException(TeeeOtpException::class);
        $this->gateway(['status' => 202, 'body' => '{"status":"queued"}'])->send('699000001', 'Bonjour');
    }

    public function testCleAbsenteRefuseSansAppelReseau(): void
    {
        $gateway = new TeeeOtpSmsGateway($this->client(['status' => 202, 'body' => '{"id":"x"}'], ''), $this->logger);
        try {
            $gateway->send('699000001', 'Bonjour');
            self::fail('Aucune exception.');
        } catch (TeeeOtpException $e) {
            self::assertSame(TeeeOtpException::NOT_CONFIGURED, $e->errorCode);
        }
        self::assertSame([], $this->calls);
    }

    public function testNiCleNiNumeroNiTexteDansLesJournaux(): void
    {
        try {
            $this->gateway(['status' => 429, 'body' => '{"error":{"code":"TOO_MANY_REQUESTS","message":"x"}}'])->send('699000001', 'Texte confidentiel');
        } catch (TeeeOtpException) {
        }

        $logs = $this->logger->dump();
        self::assertNotSame('', $logs);
        foreach ([self::KEY, '699000001', 'Texte confidentiel'] as $secret) {
            self::assertStringNotContainsString($secret, $logs);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function statuses(): array
    {
        return [
            ['queued', SmsStatusLookup::PENDING], ['sending', SmsStatusLookup::PENDING], ['sent', SmsStatusLookup::SENT],
            ['delivered', SmsStatusLookup::DELIVERED], ['failed', SmsStatusLookup::FAILED], ['expired', SmsStatusLookup::FAILED],
        ];
    }

    /** @dataProvider statuses */
    public function testSuiviDEtatTraduitLesStatutsTeeeOtp(string $teeeotp, string $expected): void
    {
        $gateway = $this->gateway(['status' => 200, 'body' => json_encode(['id' => 'sms_42', 'status' => $teeeotp], JSON_THROW_ON_ERROR)]);

        self::assertSame($expected, $gateway->lookup('teeeotp-sms_42'));
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertSame('https://teeeotp.example.test/api/v1/sms/sms_42', $this->calls[0]['url']);
        self::assertNull($this->calls[0]['body']);
    }

    public function testSuiviIgnoreLesReferencesDUnAutreFournisseur(): void
    {
        $gateway = $this->gateway(['status' => 200, 'body' => '{"status":"delivered"}']);

        self::assertNull($gateway->lookup('http-abc'));
        self::assertNull($gateway->lookup('teeeotp-'));
        self::assertSame('teeeotp-', $gateway->referencePrefix());
        self::assertSame([], $this->calls);
    }

    public function testIdentifiantDeSuiviEstEncodeDansLUrl(): void
    {
        $this->gateway(['status' => 200, 'body' => '{"status":"sent"}'])->lookup('teeeotp-../v1/me');

        self::assertSame('https://teeeotp.example.test/api/v1/sms/..%2Fv1%2Fme', $this->calls[0]['url']);
    }

    public function testSmsCheckAfficheModeEtQuotaSansLaCle(): void
    {
        $body = json_encode(['name' => 'Scholaris', 'mode' => 'test', 'dailyQuota' => 500, 'usedToday' => 3, 'testSmsRemaining' => 2, 'apiKey' => self::KEY, 'nested' => ['secretToken' => 'zzz', 'plan' => 'free']], JSON_THROW_ON_ERROR);
        $command = new SmsCheckCommand($this->client(['status' => 200, 'body' => $body]), 'teeeotp', self::KEY);

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertSame('/v1/me', substr($this->calls[0]['url'], -6));
        foreach (['mode', 'test', 'dailyQuota', '500', 'usedToday', 'testSmsRemaining', 'nested.plan', 'free', 'clé de test'] as $expected) {
            self::assertStringContainsString($expected, $output);
        }
        foreach ([self::KEY, 'zzz', 'secretToken'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    public function testSmsCheckEnEchecRenvoie1(): void
    {
        $command = new SmsCheckCommand($this->client(['status' => 401, 'body' => '{"error":{"code":"INVALID_API_KEY"}}']), 'teeeotp', self::KEY);

        ob_start();
        $code = $command->run();
        $output = (string) ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('UNAUTHORIZED', $output);
        self::assertStringNotContainsString(self::KEY, $output);
    }
}
