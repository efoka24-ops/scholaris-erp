<?php

declare(strict_types=1);

namespace Tests\Isolation;

use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Tests\Support\Billing\BillingTestCase;

/** Cloisonnement du module Billing : par client (tenant) et par établissement. */
final class BillingIsolationTest extends BillingTestCase
{
    private string $invoiceId;
    private string $paymentId;
    private string $receiptId;

    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceId = $this->invoiceFor('yann', 'g1', 'en1')['id'];
        $this->reference('g1');
        $this->openCash();
        [, $payment] = $this->pay('cashier', ['amount_xaf' => 60000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->paymentId = $payment['data']['id'];
        $this->receiptId = $payment['data']['receipt']['id'];

        // Un autre client, avec ses propres utilisateurs, établissements et droits.
        $this->access->grant('spy', 'x1');
        $this->access->grant('spy', 'e1');
        $this->permissions->grant('spy', 'billing.read', 'billing.manage', 'billing.cash', 'billing.reversal.request', 'billing.reversal.approve');
    }

    public function testUnAutreClientNeVoitRienEtNeModifieRien(): void
    {
        foreach ([
            '/invoices/' . $this->invoiceId, '/payments/' . $this->paymentId, '/receipts/' . $this->receiptId, '/receipts/' . $this->receiptId . '/pdf',
        ] as $path) {
            [$response] = $this->api('GET', $path, 'spy', null, 't2', 'x1');
            self::assertSame(404, $response->getStatusCode(), $path);
        }

        foreach (['/invoices', '/payments', '/receipts', '/fee-items', '/fee-schedules', '/payment-references', '/reversals', '/cash-sessions'] as $path) {
            [, $json] = $this->api('GET', $path, 'spy', null, 't2', 'x1');
            self::assertSame([], $json['data'], $path);
        }

        [$balance] = $this->api('GET', '/billing/families/FAM-0001/balance', 'spy', null, 't2', 'x1');
        self::assertSame(404, $balance->getStatusCode());

        [$pay] = $this->api('POST', '/payments', 'spy', ['channel' => 'transfer', 'amount_xaf' => 1000, 'family_reference' => 'FAM-0001'], 't2', 'x1', ['Idempotency-Key' => 'cle-intrus-0001']);
        self::assertSame(404, $pay->getStatusCode(), 'La référence famille d\'un autre client est inconnue.');
        [$reverse] = $this->api('POST', '/reversals', 'spy', ['payment_id' => $this->paymentId, 'reason' => 'Intrusion'], 't2', 'x1');
        self::assertSame(404, $reverse->getStatusCode());
        self::assertSame(60000, (int) $this->rows('SELECT SUM(paid_xaf) AS s FROM invoices')[0]['s']);
    }

    public function testUnEnTeteDEtablissementDUnAutreClientEstRefuse(): void
    {
        // « spy » est du client t2 mais prétend agir dans l'établissement e1 du client t1 : l'accès (droits) l'autorise
        // ici pour tester le cloisonnement des données : rien de t1 n'est lisible sous le jeton de t2.
        [$list, $json] = $this->api('GET', '/invoices', 'spy', null, 't2', 'e1');
        self::assertSame(200, $list->getStatusCode());
        self::assertSame([], $json['data']);

        [$stranger] = $this->api('GET', '/invoices', 'spy', null, 't2', 'e2');
        self::assertSame(403, $stranger->getStatusCode(), 'Établissement sans droit accordé.');
    }

    public function testCloisonnementParEtablissementDansLeMemeClient(): void
    {
        $this->access->grant('local', 'e2');
        $this->permissions->grant('local', 'billing.read', 'billing.manage', 'billing.cash', 'billing.reversal.request');

        foreach (['/invoices/' . $this->invoiceId, '/payments/' . $this->paymentId, '/receipts/' . $this->receiptId] as $path) {
            [$response] = $this->api('GET', $path, 'local', null, 't1', 'e2');
            self::assertSame(404, $response->getStatusCode(), $path);
        }
        [, $payments] = $this->api('GET', '/payments', 'local', null, 't1', 'e2');
        self::assertSame([], $payments['data']);
        [$reverse] = $this->api('POST', '/reversals', 'local', ['payment_id' => $this->paymentId, 'reason' => 'Autre établissement'], 't1', 'e2');
        self::assertSame(404, $reverse->getStatusCode());
        [, $items] = $this->api('GET', '/fee-items', 'local', null, 't1', 'e2');
        self::assertSame([], $items['data'], 'Le catalogue de frais est propre à chaque établissement.');
    }

    public function testDebtorDirectoryEstCloisonne(): void
    {
        $this->clock->set('2027-03-01T09:00:00+01:00');
        $directory = $this->container()->get(DebtorDirectory::class);

        self::assertSame(['yann'], $directory->learnerIdsInArrears('t1', 'e1'));
        self::assertSame([], $directory->learnerIdsInArrears('t2', 'e1'));
        self::assertSame([], $directory->learnerIdsInArrears('t1', 'e2'));
        self::assertSame(0, $directory->balanceXaf('t2', 'e1', 'yann'));
        self::assertSame(0, $directory->balanceXaf('t1', 'e2', 'yann'));
        self::assertGreaterThan(0, $directory->balanceXaf('t1', 'e1', 'yann'));
        self::assertFalse($directory->isBlocked('t2', 'e1', 'yann', 'results'));
    }
}
