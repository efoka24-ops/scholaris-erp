<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentWebhookEvent;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuPaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuWebhook;

/** Port PaymentGateway sur Sungku : règles de la procédure d'intégration (échec prouvé ou non, webhook signé). */
final class SungkuPaymentGatewayTest extends TestCase
{
    private const SECRET = 'secret-webhook-test';
    private const NOW = 1_800_000_000;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    private function gateway(int $status, string $body = '{}', bool $network = true): SungkuPaymentGateway
    {
        $client = new SungkuClient('https://sungku.example', 'cle-test', 15, function (string $method, string $url, array $headers, string $payload) use ($status, $body, $network): array {
            $this->requests[] = ['url' => $url, 'payload' => json_decode($payload, true)];
            if (!$network) {
                throw new \RuntimeException('réseau coupé');
            }

            return ['status' => $status, 'body' => $body];
        });

        return new SungkuPaymentGateway($client, new SungkuWebhook(self::SECRET, 600, static fn (): int => self::NOW), new NullLogger());
    }

    /** @return array<string, string> */
    private function signedHeaders(string $body, ?string $signature = null): array
    {
        $timestamp = (string) self::NOW;

        return [
            'x-apisungku-timestamp' => $timestamp,
            'x-apisungku-signature' => $signature ?? 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET),
        ];
    }

    public function testInitiationEnvoieUneReferenceUniqueEtUnNumeroInternationalEtResteEnAttente(): void
    {
        $gateway = $this->gateway(201, '{"id":"d1"}');
        $first = $gateway->initiate(35000, 'orange_money', '+237 699 12 34 56', 'FAM-0587');
        $second = $gateway->initiate(35000, 'orange_money', '+237 699 12 34 56', 'FAM-0587');

        self::assertSame('pending', $first['status'], 'Jamais de succès à l\'initiation.');
        self::assertStringStartsWith('SCH-', $first['provider_reference']);
        self::assertNotSame($first['provider_reference'], $second['provider_reference'], 'Une référence par transaction.');
        self::assertSame('237699123456', $this->requests[0]['payload']['phoneNumber']);
        self::assertSame($first['provider_reference'], $this->requests[0]['payload']['reference']);
        self::assertSame(35000, $this->requests[0]['payload']['amount']);
        self::assertSame('FAM-0587', $this->requests[0]['payload']['metadata']['family_reference']);
    }

    public function testRefusClairDeSungkuEstUnEchecProuveEn422(): void
    {
        try {
            $this->gateway(400)->initiate(35000, 'mtn_momo', '237677000000', 'FAM-1');
            self::fail('Refus attendu.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    /** @dataProvider notProven */
    public function testEchecNonProuveLaisseLaTransactionEnAttente(int $status, bool $network): void
    {
        $result = $this->gateway($status, '{}', $network)->initiate(35000, 'mtn_momo', '237677000000', 'FAM-1');

        self::assertSame('pending', $result['status']);
        self::assertStringStartsWith('SCH-', $result['provider_reference'], 'La référence est conservée : l\'argent a peut-être bougé.');
    }

    /** @return array<string, array{int, bool}> */
    public static function notProven(): array
    {
        return ['délai ou réseau coupé' => [0, false], 'erreur serveur 502' => [502, true], 'trop de requêtes' => [429, true]];
    }

    public function testWebhookConfirmeEstTraduitEnSucces(): void
    {
        $body = '{"reference":"SCH-01ABC","status":"CONFIRMED","amount":35000,"phoneNumber":"237699123456","metadata":{"family_reference":"FAM-0587","channel":"orange_money"}}';
        $event = $this->gateway(200)->parseWebhook($body, $this->signedHeaders($body));

        self::assertSame(PaymentWebhookEvent::SUCCEEDED, $event->type);
        self::assertSame('SCH-01ABC', $event->providerReference);
        self::assertSame(35000, $event->amountXaf);
        self::assertSame('FAM-0587', $event->reference);
        self::assertSame('orange_money', $event->channel);
        self::assertSame('SCH-01ABC:CONFIRMED', $event->eventId, 'Même transition rejouée = même identifiant = doublon.');
    }

    public function testStatutInconnuNEstJamaisUnSucces(): void
    {
        $body = '{"reference":"SCH-01ABC","status":"COMPLETED","amount":35000}';

        self::assertSame(PaymentWebhookEvent::PENDING, $this->gateway(200)->parseWebhook($body, $this->signedHeaders($body))->type);
    }

    public function testEchecDeSungkuEstUnEchec(): void
    {
        $body = '{"reference":"SCH-01ABC","status":"FAILED","amount":35000}';

        self::assertSame(PaymentWebhookEvent::FAILED, $this->gateway(200)->parseWebhook($body, $this->signedHeaders($body))->type);
    }

    public function testSignatureInvalideOuAbsenteEstRefusee(): void
    {
        $body = '{"reference":"SCH-01ABC","status":"CONFIRMED","amount":35000}';
        $gateway = $this->gateway(200);

        foreach ([$this->signedHeaders($body, 'sha256=' . str_repeat('a', 64)), [], ['x-apisungku-timestamp' => (string) self::NOW]] as $headers) {
            try {
                $gateway->parseWebhook($body, $headers);
                self::fail('Refus attendu.');
            } catch (HttpException $e) {
                self::assertSame(401, $e->status);
            }
        }
    }

    public function testCorpsAltereApresSignatureEstRefuse(): void
    {
        $body = '{"reference":"SCH-01ABC","status":"CONFIRMED","amount":35000}';
        $headers = $this->signedHeaders($body);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);
        $this->gateway(200)->parseWebhook(str_replace('35000', '350000', $body), $headers);
    }

    public function testWebhookSansReferenceOuMontantEstInvalide(): void
    {
        $body = '{"status":"CONFIRMED"}';

        try {
            $this->gateway(200)->parseWebhook($body, $this->signedHeaders($body));
            self::fail('Validation attendue.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('reference', $e->errors);
            self::assertArrayHasKey('amount', $e->errors);
        }
    }
}
