<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Annulation d'un paiement : écriture inverse motivée, validée par une AUTRE personne. */
final class ReversalApprovalTest extends BillingTestCase
{
    private string $paymentId;
    private string $invoiceId;

    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceId = $this->invoiceFor('yann', 'g1', 'en1')['id'];
        $this->reference('g1');
        $this->openCash();
        $this->clock->set('2026-10-10T10:00:00+01:00');
        [, $json] = $this->pay('cashier', ['amount_xaf' => 170000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        $this->paymentId = $json['data']['id'];
    }

    /** @return array<string, mixed> */
    private function request(string $user = 'cashier'): array
    {
        [$response, $json] = $this->api('POST', '/reversals', $user, ['payment_id' => $this->paymentId, 'reason' => 'Erreur de saisie du montant']);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    public function testLeDemandeurNePeutJamaisValiderSaPropreAnnulation(): void
    {
        // cashier2 détient aussi la permission de validation : il demande, puis tente de se valider lui-même.
        $reversal = $this->request('cashier2');
        [$response] = $this->api('POST', '/reversals/' . $reversal['id'] . '/approve', 'cashier2');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('confirmed', $this->rows('SELECT status FROM payments')[0]['status']);
        self::assertSame('pending', $this->rows('SELECT status FROM reversals')[0]['status']);
    }

    public function testSansPermissionDeValidationLaReponseEstRefusee(): void
    {
        $reversal = $this->request();
        [$response] = $this->api('POST', '/reversals/' . $reversal['id'] . '/approve', 'cashier');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testEnAttenteLePaiementGardeSesEffetsEtLeJournalMontreUnMontantNegatif(): void
    {
        $this->request();
        [, $json] = $this->api('GET', '/payments', 'reader');

        self::assertSame('Annulation · 2nd agent requis', $json['data'][0]['journal_status']);
        self::assertSame(-170000, $json['data'][0]['journal_amount_xaf']);
        self::assertSame(170000, $json['data'][0]['amount_xaf']);
        self::assertSame(170000, (int) $this->rows('SELECT paid_xaf FROM invoices')[0]['paid_xaf']);
    }

    public function testUnSecondAgentValideEtLEcritureInverseRestitueLesTranches(): void
    {
        $reversal = $this->request();
        self::assertSame(-170000, $reversal['amount_xaf']);

        [$response, $json] = $this->api('POST', '/reversals/' . $reversal['id'] . '/approve', 'supervisor');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('approved', $json['data']['status']);
        self::assertSame('supervisor', $json['data']['approved_by']);

        self::assertSame('reversed', $this->rows('SELECT status FROM payments')[0]['status']);
        self::assertSame('cancelled', $this->rows('SELECT status FROM receipts')[0]['status'], 'Le reçu n\'est pas supprimé : il est marqué annulé.');
        $invoice = $this->rows('SELECT paid_xaf, balance_xaf, status FROM invoices')[0];
        self::assertSame(0, (int) $invoice['paid_xaf']);
        self::assertSame(500000, (int) $invoice['balance_xaf']);
        self::assertSame('open', $invoice['status']);
        self::assertSame(170000, (int) $this->rows('SELECT SUM(amount_xaf) AS s FROM allocations WHERE amount_xaf > 0')[0]['s']);
        self::assertSame(-170000, (int) $this->rows('SELECT SUM(amount_xaf) AS s FROM allocations WHERE amount_xaf < 0')[0]['s']);

        [, $payments] = $this->api('GET', '/payments', 'reader');
        self::assertSame('Annulé', $payments['data'][0]['journal_status']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) AS n FROM audit_log WHERE action = \'payment.reversed\' AND actor_id = \'cashier\'')[0]['n']);
        self::assertSame(1, (int) $this->rows('SELECT COUNT(*) AS n FROM audit_log WHERE action = \'payment.reversed\' AND actor_id = \'supervisor\'')[0]['n']);
    }

    public function testMotifObligatoireEtUneSeuleDemandeEnAttente(): void
    {
        [$empty] = $this->api('POST', '/reversals', 'cashier', ['payment_id' => $this->paymentId]);
        self::assertSame(422, $empty->getStatusCode());

        $this->request();
        [$double] = $this->api('POST', '/reversals', 'cashier', ['payment_id' => $this->paymentId, 'reason' => 'Encore']);
        self::assertSame(409, $double->getStatusCode());
    }

    public function testUneAnnulationRefuseeLaisseLePaiementIntact(): void
    {
        $reversal = $this->request();
        [$response, $json] = $this->api('POST', '/reversals/' . $reversal['id'] . '/reject', 'supervisor');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rejected', $json['data']['status']);
        self::assertSame('confirmed', $this->rows('SELECT status FROM payments')[0]['status']);
        [$again] = $this->api('POST', '/reversals/' . $reversal['id'] . '/approve', 'supervisor');
        self::assertSame(409, $again->getStatusCode());
    }
}
