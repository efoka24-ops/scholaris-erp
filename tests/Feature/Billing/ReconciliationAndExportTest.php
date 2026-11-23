<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Rapprochement mobile money (relevé CSV), export comptable CSV, PDF du reçu. */
final class ReconciliationAndExportTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->clock->set('2026-10-10T10:00:00+01:00');
    }

    public function testRapprochementDuReleveOperateur(): void
    {
        [, $orphan] = $this->pay('cashier', ['channel' => 'mtn_momo', 'amount_xaf' => 25000, 'external_reference' => 'MM-2002']);
        self::assertSame('Sans référence', $orphan['data']['journal_status']);
        self::assertSame(25000, $orphan['data']['credit_xaf']);

        $csv = implode("\n", [
            'date,reference,amount,phone,family_reference',
            '2026-10-10,MM-2002,25000,+237650000000,FAM-0001',
            '2026-10-10,MM-9999,10000,+237650000001,FAM-0001',
            '2026-10-10,MM-8888,5000,+237650000002,',
            '2026-10-10,MM-2002X,1,+237650000002,',
        ]);
        [$response, $json] = $this->api('POST', '/reconciliations', 'manager', ['channel' => 'mtn_momo', 'source' => 'releve-mtn-octobre.csv', 'csv' => $csv]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $statuses = [];
        foreach ($json['data']['lines'] as $line) {
            $statuses[$line['operator_reference']] = $line['status'];
        }
        self::assertSame(['MM-2002' => 'matched', 'MM-9999' => 'created', 'MM-8888' => 'unreferenced', 'MM-2002X' => 'unreferenced'], $statuses);
        self::assertSame(2, $json['data']['matched']);
        self::assertSame(2, $json['data']['unmatched']);

        // Le paiement sans référence est rattaché à la famille et son crédit est affecté ; le manquant est créé avec reçu.
        [, $after] = $this->api('GET', '/payments/' . $orphan['data']['id'], 'reader');
        self::assertSame('Rapproché', $after['data']['journal_status']);
        self::assertSame(0, $after['data']['credit_xaf']);
        self::assertCount(1, $this->rows('SELECT id FROM payments WHERE external_reference = \'MM-9999\''));
        self::assertSame(2, (int) $this->rows('SELECT COUNT(*) AS n FROM receipts')[0]['n']);

        // Rejouer le même relevé ne crée rien de plus.
        $this->api('POST', '/reconciliations', 'manager', ['channel' => 'mtn_momo', 'source' => 'releve-mtn-octobre.csv', 'csv' => $csv]);
        self::assertCount(1, $this->rows('SELECT id FROM payments WHERE external_reference = \'MM-9999\''));
    }

    public function testReleveInvalideRefuseEnEntier(): void
    {
        [$response, $json] = $this->api('POST', '/reconciliations', 'manager', [
            'channel' => 'orange_money', 'source' => 'x.csv', 'csv' => "date,reference,amount\n2026-10-10,OM-1,1000.5\n2026-10-10,OM-2,2000",
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Ligne 2', $json['errors']['csv'][0]);
        self::assertCount(0, $this->rows('SELECT id FROM reconciliations'));
    }

    public function testExportComptableCsv(): void
    {
        $this->openCash();
        $this->api('POST', '/invoices/' . $this->rows('SELECT id FROM invoices')[0]['id'] . '/discounts', 'manager', ['kind' => 'staff', 'mode' => 'fixed', 'value' => 10000, 'reason' => '=Remise "personnel"']);
        $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        foreach (['journal' => 'R-IPES-26-00001', 'sales' => 'Pension', 'discounts' => 'staff'] as $kind => $expected) {
            [$response] = $this->api('GET', '/billing/exports/' . $kind . '?from=2026-10-01&to=2026-10-31', 'manager');
            self::assertSame(200, $response->getStatusCode(), $kind);
            self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString($expected, (string) $response->getBody());
        }
        $discounts = (string) $this->api('GET', '/billing/exports/discounts?from=2026-10-01&to=2026-10-31', 'manager')[0]->getBody();
        self::assertStringContainsString('"\'=Remise ""personnel"""', $discounts, 'Neutralisation des formules et échappement CSV.');
        self::assertStringContainsString(',10000,', $discounts);

        [$bad] = $this->api('GET', '/billing/exports/journal?from=hier&to=2026-10-31', 'manager');
        self::assertSame(422, $bad->getStatusCode());
        [$unknown] = $this->api('GET', '/billing/exports/secrets?from=2026-10-01&to=2026-10-31', 'manager');
        self::assertSame(404, $unknown->getStatusCode());
    }

    public function testRecuEnPdfEtEnListe(): void
    {
        $this->openCash();
        [, $payment] = $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $receiptId = $payment['data']['receipt']['id'];

        [$pdf] = $this->api('GET', '/receipts/' . $receiptId . '/pdf', 'reader');
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertNotSame('', (string) $pdf->getBody());

        [, $list] = $this->api('GET', '/receipts', 'reader');
        self::assertSame('R-IPES-26-00001', $list['data'][0]['number']);
        self::assertNotNull($list['data'][0]['sms_sent_at']);
    }

    public function testUnSmsEnEchecNeBloquePasLePaiement(): void
    {
        $this->openCash();
        $this->messenger->fail = true;
        [$response, $json] = $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        self::assertSame(201, $response->getStatusCode());
        self::assertNull($json['data']['receipt']['sms_sent_at']);
        self::assertSame('R-IPES-26-00001', $json['data']['receipt']['number']);
    }
}
