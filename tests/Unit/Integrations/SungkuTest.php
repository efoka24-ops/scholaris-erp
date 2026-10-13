<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuClient;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuRejected;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuUnavailable;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuWebhook;

final class SungkuTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $seen = [];

    private function client(int $status, string $body = '{}', ?RuntimeException $throw = null, string $key = 'cle-secrete-du-test'): SungkuClient
    {
        return new SungkuClient('https://sungku.example', $key, 15, function (string $method, string $url, array $headers, string $payload) use ($status, $body, $throw): array {
            $this->seen = ['method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];
            if ($throw !== null) {
                throw $throw;
            }

            return ['status' => $status, 'body' => $body];
        });
    }

    /** @return array{amount: int, phoneNumber: string, reference: string} */
    private function deposit(): array
    {
        return ['amount' => 35000, 'phoneNumber' => '237699123456', 'reference' => 'FAM-0587-0001'];
    }

    public function testSucces2xxRenvoieLaReponseSansValoirPaiementReussi(): void
    {
        $result = $this->client(201, '{"id":"dep_1","status":"PENDING"}')->initiateDeposit($this->deposit());

        self::assertSame('dep_1', $result['id']);
        self::assertSame('POST', $this->seen['method']);
        self::assertSame('https://sungku.example/api/partners/deposits', $this->seen['url']);
        self::assertSame('cle-secrete-du-test', $this->seen['headers']['X-Api-Key']);
        self::assertStringContainsString('"currency":"XAF"', $this->seen['payload']);
    }

    public function testRefusClairEstUnEchecProuve(): void
    {
        $this->expectException(SungkuRejected::class);
        $this->client(422, '{"error":"invalid"}')->initiateDeposit($this->deposit());
    }

    /** @dataProvider unavailableStatuses */
    public function testEchecNonProuveResteIndisponible(int $status): void
    {
        $this->expectException(SungkuUnavailable::class);
        $this->client($status)->initiateDeposit($this->deposit());
    }

    /** @return array<string, array{int}> */
    public static function unavailableStatuses(): array
    {
        return ['trop de requêtes' => [429], 'erreur serveur' => [500], 'passerelle' => [503]];
    }

    public function testCoupureReseauNEstPasUnEchecProuveEtNeFuitePasLaCle(): void
    {
        try {
            $this->client(0, '', new RuntimeException('connexion coupée, clé cle-secrete-du-test'))->initiateDeposit($this->deposit());
            self::fail('Indisponible attendu.');
        } catch (SungkuUnavailable $e) {
            self::assertStringNotContainsString('cle-secrete-du-test', $e->getMessage());
        }
    }

    public function testMontantInvalideEstRefuseAvantTouteRequete(): void
    {
        $this->seen = [];
        try {
            $this->client(200)->initiateDeposit(['amount' => 0] + $this->deposit());
            self::fail('Refus attendu.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $this->seen);
    }

    public function testSansCleConfigureeAucuneRequeteNePart(): void
    {
        $this->seen = [];
        try {
            $this->client(200, '{}', null, '')->initiateDeposit($this->deposit());
            self::fail('Indisponible attendu.');
        } catch (SungkuUnavailable) {
        }
        self::assertSame([], $this->seen);
    }

    public function testSignatureDuWebhook(): void
    {
        $now = 1_800_000_000;
        $webhook = new SungkuWebhook('secret-webhook', 600, static fn (): int => $now);
        $body = '{"reference":"FAM-0587-0001","status":"CONFIRMED"}';
        $timestamp = (string) $now;
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, 'secret-webhook');

        self::assertTrue($webhook->verify($body, $timestamp, $signature));
        self::assertFalse($webhook->verify($body . ' ', $timestamp, $signature), 'Corps modifié.');
        self::assertFalse($webhook->verify($body, (string) ($now + 1), $signature), 'Horodatage modifié.');
        self::assertFalse($webhook->verify($body, $timestamp, 'sha256=' . str_repeat('0', 64)));
        self::assertFalse($webhook->verify($body, $timestamp, ''));
        self::assertFalse((new SungkuWebhook('', 600, static fn (): int => $now))->verify($body, $timestamp, $signature), 'Sans secret, tout est refusé.');
    }

    public function testWebhookPerimeEstRefuseContreLeRejeu(): void
    {
        $now = 1_800_000_000;
        $webhook = new SungkuWebhook('secret-webhook', 600, static fn (): int => $now);
        $old = (string) ($now - 3600);
        $signature = 'sha256=' . hash_hmac('sha256', $old . '.{}', 'secret-webhook');

        self::assertFalse($webhook->verify('{}', $old, $signature));
    }

    public function testTimestampEnMillisecondesEstAccepte(): void
    {
        $now = 1_800_000_000;
        $webhook = new SungkuWebhook('secret-webhook', 600, static fn (): int => $now);
        $timestamp = (string) ($now * 1000);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.{}', 'secret-webhook');

        self::assertTrue($webhook->verify('{}', $timestamp, $signature));
    }

    public function testCorrespondanceDesStatuts(): void
    {
        self::assertSame('confirmed', SungkuWebhook::internalStatus('CONFIRMED'), 'Sungku envoie CONFIRMED, pas COMPLETED.');
        self::assertSame('pending', SungkuWebhook::internalStatus('COMPLETED'), 'Statut inconnu : jamais supposé réussi.');
        self::assertSame('failed', SungkuWebhook::internalStatus('failed'));
        self::assertSame('pending', SungkuWebhook::internalStatus('PROCESSING'));
        self::assertSame('pending', SungkuWebhook::internalStatus(''));
    }
}
