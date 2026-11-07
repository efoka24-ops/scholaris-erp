<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;
use Tests\Support\Exports\FetchesCsv;
use Tests\Support\Exports\ReadsXlsx;

/** Exports CSV des factures, paiements et reçus : contenu, droit de lecture, isolation établissement / client. */
final class ListExportTest extends BillingTestCase
{
    use FetchesCsv;
    use ReadsXlsx;

    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->clock->set('2026-10-10T10:00:00+01:00');
        $this->openCash();
        $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->access->grant('manager', 'x1');
    }

    /** @return array<string, string> */
    private function as(string $user = 'manager', string $tenant = 't1', string $establishment = 'e1'): array
    {
        return ['Authorization' => 'Bearer ' . $user . ':' . $tenant, 'X-Establishment-Id' => $establishment];
    }

    public function testFormatExcelPourFacturesPaiementsEtRecus(): void
    {
        $invoices = $this->xlsxRows('/invoices/export?format=xlsx', $this->as('reader'));
        self::assertCount(2, $invoices);
        self::assertContains('500000', $invoices[1], 'Montant en nombre.');
        self::assertCount(2, $this->xlsxRows('/payments/export?format=xlsx&filter[channel]=cash', $this->as('reader')));
        self::assertCount(2, $this->xlsxRows('/receipts/export?format=xlsx', $this->as('reader')));
        self::assertSame(403, $this->fetch('/invoices/export?format=xlsx', $this->as('nobody'))->getStatusCode());
        self::assertSame(422, $this->fetch('/invoices/export?format=pdf', $this->as('reader'))->getStatusCode());
    }
    public function testExportDesFacturesPaiementsEtRecus(): void
    {
        $invoices = $this->csvRows('/invoices/export', $this->as('reader'));
        self::assertSame('Identifiant', $invoices[0][0]);
        self::assertCount(2, $invoices);
        self::assertContains('500000', $invoices[1], 'Montant brut de la facture.');

        $payments = $this->csvRows('/payments/export?filter[channel]=cash', $this->as('reader'));
        self::assertCount(2, $payments);
        self::assertContains('60000', $payments[1]);
        self::assertContains('R-IPES-26-00001', $payments[1]);

        $receipts = $this->csvRows('/receipts/export', $this->as('reader'));
        self::assertCount(2, $receipts);
        self::assertContains('R-IPES-26-00001', $receipts[1]);

        self::assertCount(2, $this->csvRows('/payments/export?filter[day]=2026-10-10', $this->as('reader')));
        self::assertCount(1, $this->csvRows('/payments/export?filter[channel]=mtn_momo', $this->as('reader')), 'Filtre appliqué : en-tête seul.');
        self::assertSame(422, $this->fetch('/payments/export?filter[day]=hier', $this->as())->getStatusCode());
        self::assertSame(422, $this->fetch('/invoices/export?filter[secret]=1', $this->as())->getStatusCode());
    }

    public function testLaLectureDeLaListeEstExigee(): void
    {
        $this->access->grant('nobody', 'e1');
        foreach (['/invoices/export', '/payments/export', '/receipts/export'] as $path) {
            self::assertSame(403, $this->fetch($path, $this->as('nobody'))->getStatusCode(), $path);
        }
    }

    public function testIsolationEntreEtablissementsEtEntreClients(): void
    {
        foreach (['/invoices/export', '/payments/export', '/receipts/export'] as $path) {
            self::assertCount(1, $this->csvRows($path, $this->as('manager', 't1', 'e2')), $path . ' : autre établissement');
            self::assertCount(1, $this->csvRows($path, $this->as('manager', 't2', 'x1')), $path . ' : autre client');
        }
    }
}
