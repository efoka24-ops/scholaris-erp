<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Scholaris\Modules\Socle\Integrations\Infrastructure\LogSmsGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPaymentGateway;
use Scholaris\Modules\Socle\Integrations\Infrastructure\SimulatedPdfRenderer;

final class IntegrationPortsTest extends TestCase
{
    public function testSmsSimuleRenvoieUneReference(): void
    {
        self::assertStringStartsWith('sim-sms-', (new LogSmsGateway(new NullLogger()))->send('+237699123456', 'Bonjour'));
    }

    public function testPaiementSimuleRefuseUnMontantNonPositif(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SimulatedPaymentGateway('secret'))->initiate(0, 'orange_money', '+237699123456', 'FAM-0001');
    }

    public function testPaiementSimuleInitieUnePaiementEnAttente(): void
    {
        $result = (new SimulatedPaymentGateway('secret'))->initiate(35000, 'mtn_momo', '+237677000000', 'FAM-0001');

        self::assertSame('pending', $result['status']);
        self::assertStringStartsWith('sim-pay-', $result['provider_reference']);
    }

    public function testSignatureDeWebhook(): void
    {
        $gateway = new SimulatedPaymentGateway('secret');
        $payload = '{"event_id":"e1","amount":35000}';
        $valid = hash_hmac('sha256', $payload, 'secret');

        self::assertTrue($gateway->verifySignature($payload, $valid));
        self::assertFalse($gateway->verifySignature($payload . ' ', $valid));
        self::assertFalse($gateway->verifySignature($payload, ''));
        self::assertFalse((new SimulatedPaymentGateway(''))->verifySignature($payload, $valid), 'Sans secret configuré, tout est refusé.');
    }

    public function testPdfSimule(): void
    {
        self::assertStringStartsWith('%PDF-', (new SimulatedPdfRenderer())->render('<h1>Reçu</h1>'));
    }
}
