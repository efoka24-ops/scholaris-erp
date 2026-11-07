<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\HttpSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sms\SmsGatewayException;
use Tests\Support\Messaging\ArrayLogger;

/** Adaptateur SMS HTTP générique, testé avec un faux client HTTP (aucun réseau). */
final class HttpSmsGatewayTest extends TestCase
{
    private const TOKEN = 'tok_secret_123';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, timeout: int}> */
    private array $calls = [];
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
    }

    /**
     * @param array{status: int, body: string}|\Throwable $response
     * @param array<string, mixed> $overrides
     */
    private function gateway(array|\Throwable $response = ['status' => 202, 'body' => '{"data":{"id":"abc-42"}}'], array $overrides = []): HttpSmsGateway
    {
        $transport = function (string $method, string $url, array $headers, string $body, int $timeout) use ($response): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        };
        $args = $overrides + [
            'url' => 'https://sms.example.test/v1/send',
            'method' => 'POST',
            'headers' => ['X-Account' => 'scholaris'],
            'token' => self::TOKEN,
            'tokenHeader' => 'Authorization',
            'tokenPrefix' => 'Bearer ',
            'bodyTemplate' => ['recipient' => '{{to}}', 'content' => ['text' => '{{message}}'], 'from' => '{{sender}}'],
            'sender' => 'SCHOLARIS',
            'referencePath' => 'data.id',
            'timeoutSeconds' => 7,
        ];

        return new HttpSmsGateway(...$args, logger: $this->logger, transport: $transport);
    }

    public function testRequeteConstruiteDepuisLaConfiguration(): void
    {
        $reference = $this->gateway()->send('+237670000001', 'Reçu : "35000" FCFA');

        self::assertSame('http-abc-42', $reference);
        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://sms.example.test/v1/send', $call['url']);
        self::assertSame(7, $call['timeout']);
        self::assertSame('Bearer ' . self::TOKEN, $call['headers']['Authorization']);
        self::assertSame('scholaris', $call['headers']['X-Account']);
        self::assertSame(['recipient' => '+237670000001', 'content' => ['text' => 'Reçu : "35000" FCFA'], 'from' => 'SCHOLARIS'], json_decode($call['body'], true));
    }

    public function testUneValeurNePeutPasCasserLaStructureJson(): void
    {
        $this->gateway()->send('+237670000001', '"},"admin":true,"x":{"y":"');

        $decoded = json_decode($this->calls[0]['body'], true);
        self::assertSame(['recipient', 'content', 'from'], array_keys($decoded));
        self::assertSame('"},"admin":true,"x":{"y":"', $decoded['content']['text']);
    }

    public function testJetonDansUnEnTeteSpecifique(): void
    {
        $this->gateway(overrides: ['tokenHeader' => 'X-Api-Key', 'tokenPrefix' => ''])->send('+237670000001', 'Salut');

        self::assertSame(self::TOKEN, $this->calls[0]['headers']['X-Api-Key']);
        self::assertArrayNotHasKey('Authorization', $this->calls[0]['headers']);
    }

    public function testReferenceGenereeQuandLaReponseNEnContientPas(): void
    {
        $reference = $this->gateway(['status' => 200, 'body' => 'OK'])->send('+237670000001', 'Salut');

        self::assertStringStartsWith('http-sms-', $reference);
    }

    public function testRefusDuFournisseur(): void
    {
        try {
            $this->gateway(['status' => 401, 'body' => '{"error":"bad token tok_secret_123"}'])->send('+237670000001', 'Salut');
            self::fail('HTTP 401 : une exception est attendue.');
        } catch (SmsGatewayException $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testPanneReseauSansFuiteDuJetonNiDuNumero(): void
    {
        try {
            $this->gateway(new \RuntimeException('connexion à https://sms.example.test avec Bearer ' . self::TOKEN . ' pour +237670000001'))->send('+237670000001', 'Texte privé');
            self::fail('Panne : une exception est attendue.');
        } catch (SmsGatewayException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('+237670000001', $e->getMessage());
        }
        $dump = $this->logger->dump();
        foreach ([self::TOKEN, '+237670000001', 'Texte privé'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $dump);
        }
    }

    public function testNumeroInvalideEtUrlAbsente(): void
    {
        foreach (['', 'abc', '+237 670', "+23767000\r\n"] as $bad) {
            try {
                $this->gateway()->send($bad, 'Salut');
                self::fail('Numéro invalide accepté.');
            } catch (SmsGatewayException) {
            }
        }
        self::assertSame([], $this->calls);

        $this->expectException(SmsGatewayException::class);
        $this->gateway(overrides: ['url' => ''])->send('+237670000001', 'Salut');
    }

    public function testTransportParDefautExigeHttps(): void
    {
        $gateway = new HttpSmsGateway('http://sms.example.test/send', logger: $this->logger);

        $this->expectException(SmsGatewayException::class);
        $gateway->send('+237670000001', 'Salut');
    }
}
