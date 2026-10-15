<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Un paiement rejoué avec la même clé d'idempotence ne crédite et ne numérote jamais deux fois. */
final class PaymentIdempotencyTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->openCash();
    }

    public function testLeRejeuHttpRenvoieLaMemeReponseSansSecondCredit(): void
    {
        $body = ['amount_xaf' => 50000, 'family_reference' => 'FAM-0001'];
        [$first, $one] = $this->pay('cashier', $body, '/cash/receipts', 'cle-identique-01');
        [$second, $two] = $this->pay('cashier', $body, '/cash/receipts', 'cle-identique-01');

        self::assertSame(201, $first->getStatusCode());
        self::assertSame('true', $second->getHeaderLine('Idempotent-Replayed'));
        self::assertSame($one, $two);
        self::assertCount(1, $this->rows('SELECT id FROM payments'));
        self::assertCount(1, $this->rows('SELECT id FROM receipts'));
        self::assertSame(50000, (int) $this->rows('SELECT SUM(amount_xaf) AS s FROM allocations')[0]['s']);
    }

    public function testMemeCleAvecUnAutreMontantEstRefusee(): void
    {
        $this->pay('cashier', ['amount_xaf' => 50000, 'family_reference' => 'FAM-0001'], '/cash/receipts', 'cle-identique-02');
        [$response] = $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts', 'cle-identique-02');

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(1, $this->rows('SELECT id FROM payments'));
    }

    public function testCleObligatoire(): void
    {
        [$response] = $this->api('POST', '/cash/receipts', 'cashier', ['amount_xaf' => 1000, 'family_reference' => 'FAM-0001']);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testLeServiceEstIdempotentMemeSansLeMiddleware(): void
    {
        $service = $this->container()->get(\Scholaris\Modules\Socle\Billing\Application\PaymentService::class);
        $command = new \Scholaris\Modules\Socle\Billing\Application\RecordPayment(
            't1',
            'e1',
            'cashier',
            \Scholaris\Modules\Socle\Billing\Domain\PaymentChannel::Cash,
            20000,
            'direct-0001',
            'FAM-0001',
        );

        $first = $service->record($command);
        $second = $service->record($command);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->payment['id'], $second->payment['id']);
        self::assertSame($first->receipt['number'] ?? null, $second->receipt['number'] ?? 'autre');
        self::assertCount(1, $this->rows('SELECT id FROM receipts'));
    }

    public function testReferenceExterneUnique(): void
    {
        $mobile = ['channel' => 'orange_money', 'amount_xaf' => 10000, 'family_reference' => 'FAM-0001', 'external_reference' => 'OM-123456'];
        [$first] = $this->pay('cashier', $mobile);
        [$second, $json] = $this->pay('cashier', $mobile);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode(), (string) $second->getBody());
        self::assertArrayHasKey('detail', $json);
        self::assertCount(1, $this->rows('SELECT id FROM payments WHERE channel = \'orange_money\''));
    }

    public function testUnEspeceSansCaisseOuverteEstRefuse(): void
    {
        [$response] = $this->pay('cashier2', ['amount_xaf' => 1000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        self::assertSame(409, $response->getStatusCode());
        self::assertCount(0, $this->rows('SELECT id FROM payments'));
    }

    public function testPaiementSansCibleRefuse(): void
    {
        [$response, $json] = $this->pay('cashier', ['amount_xaf' => 1000], '/cash/receipts');

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('family_reference', $json['errors']);
    }
}
