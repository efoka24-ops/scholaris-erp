<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Kernel;
use Scholaris\Modules\Socle\Integrations\Contracts\PaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\Sungku\SungkuPaymentGateway;

/** Garde-fou : Sungku (appels réels) n'est choisi que si c'est explicite ET si la clé API est renseignée. */
final class PaymentGatewaySelectionTest extends TestCase
{
    /** @param array<string, mixed> $sungku */
    private function gateway(array $sungku): PaymentGateway
    {
        $kernel = Kernel::boot(dirname(__DIR__, 2), Config::fromArray([
            'app' => ['timezone' => 'Africa/Douala', 'log_path' => 'storage/logs/test.log', 'api_prefix' => '/api/v1'],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'sungku' => $sungku,
        ]));

        return $kernel->container()->get(PaymentGateway::class);
    }

    public function testParDefautLeSimulateurEstUtilise(): void
    {
        self::assertInstanceOf(SimulatedPaymentGateway::class, $this->gateway([]));
    }

    public function testSungkuNonActiveMemeAvecUneCleResteSimule(): void
    {
        self::assertInstanceOf(SimulatedPaymentGateway::class, $this->gateway(['enabled' => false, 'api_key' => 'sk_x']));
    }

    public function testSungkuActiveSansCleResteSimule(): void
    {
        self::assertInstanceOf(SimulatedPaymentGateway::class, $this->gateway(['enabled' => true, 'api_key' => '']));
    }

    public function testSungkuActiveAvecCleEstUtilise(): void
    {
        $gateway = $this->gateway(['enabled' => true, 'api_key' => 'sk_test', 'base_url' => 'https://sungku.example', 'webhook_secret' => 's']);

        self::assertInstanceOf(SungkuPaymentGateway::class, $gateway);
    }
}
