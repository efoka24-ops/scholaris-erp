<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Montants entiers en XAF, validation stricte des corps de requête, refus par défaut des permissions. */
final class ValidationAndAccessTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->openCash();
    }

    /** @return iterable<string, array{0: string}> */
    public static function montantsInvalides(): iterable
    {
        yield 'décimal' => ['{"amount_xaf": 1000.5, "family_reference": "FAM-0001"}'];
        yield 'décimal entier' => ['{"amount_xaf": 1000.0, "family_reference": "FAM-0001"}'];
        yield 'texte' => ['{"amount_xaf": "1000", "family_reference": "FAM-0001"}'];
        yield 'zéro' => ['{"amount_xaf": 0, "family_reference": "FAM-0001"}'];
        yield 'négatif' => ['{"amount_xaf": -500, "family_reference": "FAM-0001"}'];
        yield 'absent' => ['{"family_reference": "FAM-0001"}'];
    }

    /** @dataProvider montantsInvalides */
    public function testMontantsNonEntiersRefuses(string $body): void
    {
        $response = $this->call('POST', '/api/v1/cash/receipts', [
            'Authorization' => 'Bearer cashier:t1', 'X-Establishment-Id' => 'e1', 'Content-Type' => 'application/json', 'Idempotency-Key' => 'cle-montant-' . md5($body),
        ], $body);

        self::assertSame(422, $response->getStatusCode(), $body);
        self::assertCount(0, $this->rows('SELECT id FROM payments'));
    }

    public function testFraisEtGrillesRefusentLesDecimales(): void
    {
        [$item] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 450000.5]);
        self::assertSame(422, $item->getStatusCode());

        $itemId = $this->rows('SELECT id FROM fee_items LIMIT 1')[0]['id'];
        [$schedule, $json] = $this->api('POST', '/fee-schedules', 'manager', [
            'fee_item_id' => $itemId, 'academic_year_id' => 'ay9', 'amount_xaf' => 300000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 100000], ['due_on' => '2027-01-15', 'amount' => 100000.5]],
        ]);
        self::assertSame(422, $schedule->getStatusCode());
        self::assertArrayHasKey('terms', $json['errors']);
    }

    public function testSommeDesTranchesDoitEgalerLeMontant(): void
    {
        $itemId = $this->rows('SELECT id FROM fee_items LIMIT 1')[0]['id'];
        [$response, $json] = $this->api('POST', '/fee-schedules', 'manager', [
            'fee_item_id' => $itemId, 'academic_year_id' => 'ay9', 'amount_xaf' => 300000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 100000]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('somme des tranches', $json['errors']['terms'][0]);
    }

    public function testChampsInconnusEtRemisesInvalidesRefuses(): void
    {
        [$extra, $json] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 1000, 'tenant_id' => 't2']);
        self::assertSame(422, $extra->getStatusCode());
        self::assertArrayHasKey('tenant_id', $json['errors']);

        $invoiceId = $this->rows('SELECT id FROM invoices')[0]['id'];
        [$over] = $this->api('POST', '/invoices/' . $invoiceId . '/discounts', 'manager', ['kind' => 'merit', 'mode' => 'percent', 'value' => 120, 'reason' => 'x']);
        self::assertSame(422, $over->getStatusCode());
    }

    public function testRefusParDefautSansPermission(): void
    {
        $this->access->grant('nobody', 'e1');
        $checks = [
            ['GET', '/fee-items'], ['POST', '/fee-items'], ['GET', '/invoices'], ['POST', '/invoices'], ['GET', '/payments'],
            ['POST', '/payments'], ['POST', '/cash/receipts'], ['GET', '/receipts'], ['GET', '/cash-sessions'], ['POST', '/cash-sessions'],
            ['GET', '/reversals'], ['POST', '/reversals'], ['GET', '/reconciliations'], ['POST', '/reconciliations'],
            ['GET', '/payment-references'], ['GET', '/billing/families/FAM-0001/balance'], ['GET', '/billing/exports/journal'],
        ];
        foreach ($checks as [$method, $path]) {
            [$response] = $this->api($method, $path, 'nobody', $method === 'POST' ? ['amount_xaf' => 1] : null, 't1', 'e1', ['Idempotency-Key' => 'cle-refus-000001']);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
        }
    }

    public function testSeparationDesRoles(): void
    {
        [$readerPay] = $this->pay('reader', ['amount_xaf' => 1000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(403, $readerPay->getStatusCode());

        [$managerCash] = $this->pay('manager', ['amount_xaf' => 1000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(403, $managerCash->getStatusCode(), 'La caisse est réservée à billing.cash.');

        [$cashierManage] = $this->api('POST', '/fee-items', 'cashier', ['kind' => 'other', 'name' => 'Uniforme', 'amount_xaf' => 1000]);
        self::assertSame(403, $cashierManage->getStatusCode());

        [$readerReverse] = $this->api('POST', '/reversals', 'reader', ['payment_id' => 'x', 'reason' => 'x']);
        self::assertSame(403, $readerReverse->getStatusCode());
    }

    public function testListesPagineesAvecListeBlancheDeFiltres(): void
    {
        [$ok, $json] = $this->api('GET', '/fee-items?per_page=1', 'reader');
        self::assertSame(200, $ok->getStatusCode());
        self::assertCount(1, $json['data']);
        self::assertNotNull($json['meta']['next_cursor']);
        self::assertArrayNotHasKey('tenant_id', $json['data'][0]);

        [$bad] = $this->api('GET', '/fee-items?filter[tenant_id]=t2', 'reader');
        self::assertSame(422, $bad->getStatusCode());
    }
}
