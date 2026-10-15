<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Caisse : ouverture avec fonds, encaissements, arrêté avec écart nominatif, versements. */
final class CashSessionTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
    }

    public function testArreteAvecEcartNominatif(): void
    {
        $id = $this->openCash('cashier', 50000);
        $this->pay('cashier', ['amount_xaf' => 30000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->pay('cashier', ['amount_xaf' => 20000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        [, $live] = $this->api('GET', '/cash-sessions/' . $id, 'cashier');
        self::assertSame(50000, $live['data']['cash_collected_xaf']);
        self::assertSame(2, $live['data']['receipts_count']);
        self::assertSame(100000, $live['data']['expected_now_xaf']);

        [$response, $json] = $this->api('POST', '/cash-sessions/' . $id . '/close', 'cashier', ['counted_xaf' => 99000, 'note' => 'Billet déchiré']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('closed', $json['data']['status']);
        self::assertSame(100000, $json['data']['expected_xaf']);
        self::assertSame(-1000, $json['data']['variance_xaf']);
        self::assertSame('cashier', $json['data']['variance_user_id']);
        self::assertSame(1, (int) $this->rows('SELECT COUNT(*) AS n FROM audit_log WHERE action = \'cash.closed\'')[0]['n']);

        [$again] = $this->api('POST', '/cash-sessions/' . $id . '/close', 'cashier', ['counted_xaf' => 99000]);
        self::assertSame(409, $again->getStatusCode());
        [$late] = $this->pay('cashier', ['amount_xaf' => 1000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(409, $late->getStatusCode(), 'Plus d\'encaissement une fois la caisse arrêtée.');
    }

    public function testEcartZeroEtSeulLeCaissierArreteSaCaisse(): void
    {
        $id = $this->openCash('cashier', 50000);
        $this->pay('cashier', ['amount_xaf' => 10000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        [$other] = $this->api('POST', '/cash-sessions/' . $id . '/close', 'cashier2', ['counted_xaf' => 60000]);
        self::assertSame(403, $other->getStatusCode());

        [, $json] = $this->api('POST', '/cash-sessions/' . $id . '/close', 'cashier', ['counted_xaf' => 60000]);
        self::assertSame(0, $json['data']['variance_xaf']);
    }

    public function testUneSeuleCaisseOuverteParCaissier(): void
    {
        $this->openCash();
        [$response] = $this->api('POST', '/cash-sessions', 'cashier', ['float_xaf' => 0]);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testVersementsPlafonnesAuxEspecesEncaissees(): void
    {
        $id = $this->openCash();
        $this->pay('cashier', ['amount_xaf' => 40000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        [$tooMuch] = $this->api('POST', '/cash-sessions/' . $id . '/deposits', 'cashier', ['amount_xaf' => 50000, 'reference' => 'BORD-1']);
        self::assertSame(422, $tooMuch->getStatusCode());

        [$ok, $json] = $this->api('POST', '/cash-sessions/' . $id . '/deposits', 'cashier', ['amount_xaf' => 30000, 'reference' => 'BORD-1']);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame(30000, $json['data']['deposited_xaf']);
        self::assertSame('BORD-1', $json['data']['deposits'][0]['reference']);
        self::assertArrayNotHasKey('tenant_id', $json['data']['deposits'][0]);
    }

    public function testSeulUnPaiementConfirmeCompteDansLArrete(): void
    {
        $id = $this->openCash();
        [, $payment] = $this->pay('cashier', ['amount_xaf' => 40000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $reversal = $this->api('POST', '/reversals', 'cashier', ['payment_id' => $payment['data']['id'], 'reason' => 'Doublon'])[1]['data'];
        $this->api('POST', '/reversals/' . $reversal['id'] . '/approve', 'supervisor');

        [, $json] = $this->api('POST', '/cash-sessions/' . $id . '/close', 'cashier', ['counted_xaf' => 50000]);
        self::assertSame(50000, $json['data']['expected_xaf']);
        self::assertSame(0, $json['data']['variance_xaf']);
    }
}
