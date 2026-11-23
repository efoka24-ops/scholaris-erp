<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Nyholm\Psr7\ServerRequest;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * D2 (recette) : un parent paie en mobile money depuis son portail et lit ses reçus, sur la pile réelle (rôles et
 * permissions d'Identity migrés). Le contrôle d'objet reste strict : un autre parent ne paie ni ne voit rien de lui, et
 * le libre-service ne donne aucun autre droit de facturation.
 */
final class SelfServicePaymentTest extends RealStackTestCase
{
    use BuildsSchool;

    /** @var array{person_id: string, login: string, password: string} */
    private array $parent;
    private string $parentToken = '';
    private string $otherToken = '';
    /** @var array{person_id: string, login: string, password: string} */
    private array $other;
    private string $invoiceId = '';

    protected function setUp(): void
    {
        $this->bootRealStack();
        $this->buildSchool();
        $this->parent = $this->provisionUser($this->admin, $this->school, 'Paul', 'Foka', 'parent');
        $this->other = $this->provisionUser($this->admin, $this->school, 'Rita', 'Mbella', 'parent');
        $family = $this->enrollLearner('Nadia', 'Foka', '2011-05-14', $this->parent['person_id']);
        $this->enrollLearner('Eric', 'Mbella', '2012-02-01', $this->other['person_id']);
        $item = (string) $this->ok('POST', '/fee-items', $this->admin, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 150000], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/fee-schedules', $this->admin, ['fee_item_id' => $item, 'academic_year_id' => $this->year, 'amount_xaf' => 150000, 'terms' => [['due_on' => '2026-10-15', 'amount' => 150000]]], $this->school, [], 201);
        $this->invoiceId = (string) $this->ok('POST', '/invoices', $this->admin, ['enrollment_id' => $family['enrollment']], $this->school, [], 201)['data']['id'];
        $this->parentToken = $this->signIn($this->parent['login'], $this->parent['password']);
        $this->otherToken = $this->signIn($this->other['login'], $this->other['password']);
    }

    public function testLeParentPaieSaFactureEnMobileMoneyEtLitSonRecu(): void
    {
        $lookup = $this->ok('GET', '/payment-references?filter[guarantor_id]=' . $this->parent['person_id'], $this->parentToken, null, $this->school);
        self::assertSame([], $lookup['data']);
        $reference = (string) $this->ok('POST', '/payment-references', $this->parentToken, ['guarantor_id' => $this->parent['person_id']], $this->school, [], 201)['data']['reference'];

        $pending = $this->ok('POST', '/payments', $this->parentToken, ['channel' => 'orange_money', 'amount_xaf' => 50000, 'family_reference' => $reference, 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'portail-parent-0001'], 202)['data'];
        self::assertSame('pending', $pending['status']);
        // Suivi du paiement (waitForPayment du front).
        self::assertSame('pending', $this->ok('GET', '/payments/' . $pending['id'], $this->parentToken, null, $this->school)['data']['status']);

        $event = json_encode(['event_id' => 'evt-portail-1', 'type' => 'payment.succeeded', 'amount' => 50000, 'provider_reference' => (string) $pending['external_reference'], 'reference' => $reference, 'phone' => '+237670000123'], JSON_THROW_ON_ERROR);
        [$hook] = $this->send(new ServerRequest('POST', '/api/v1/webhooks/payments/orange_money', ['Content-Type' => 'application/json', 'X-Signature' => hash_hmac('sha256', $event, self::WEBHOOK_SECRET)], $event));
        self::assertSame(200, $hook->getStatusCode(), (string) $hook->getBody());

        $receipts = $this->ok('GET', '/receipts', $this->parentToken, null, $this->school)['data'];
        self::assertCount(1, $receipts);
        $receiptId = (string) $receipts[0]['id'];
        self::assertSame($receiptId, $this->ok('GET', '/receipts/' . $receiptId, $this->parentToken, null, $this->school)['data']['id']);
        [$pdf] = $this->api('GET', '/receipts/' . $receiptId . '/pdf', $this->parentToken, null, $this->school);
        self::assertSame(200, $pdf->getStatusCode());

        // L'autre parent : rien de visible, rien de payable.
        self::assertSame([], $this->ok('GET', '/receipts', $this->otherToken, null, $this->school)['data']);
        self::assertSame([], $this->ok('GET', '/payment-references?filter[guarantor_id]=' . $this->parent['person_id'], $this->otherToken, null, $this->school)['data']);
        self::assertSame([], $this->ok('GET', '/payment-references', $this->otherToken, null, $this->school)['data']);
        foreach (['/receipts/' . $receiptId, '/receipts/' . $receiptId . '/pdf', '/payments/' . $pending['id']] as $path) {
            [$response] = $this->api('GET', $path, $this->otherToken, null, $this->school);
            self::assertSame(404, $response->getStatusCode(), $path);
        }
        [$foreignRef] = $this->api('POST', '/payments', $this->otherToken, ['channel' => 'orange_money', 'amount_xaf' => 1000, 'family_reference' => $reference, 'payer_phone' => '+237670000999'], $this->school, ['Idempotency-Key' => 'portail-autre-0001']);
        self::assertSame(404, $foreignRef->getStatusCode());
        [$foreignInvoice] = $this->api('POST', '/payments', $this->otherToken, ['channel' => 'mtn_momo', 'amount_xaf' => 1000, 'invoice_id' => $this->invoiceId, 'payer_phone' => '+237670000999'], $this->school, ['Idempotency-Key' => 'portail-autre-0002']);
        self::assertSame(404, $foreignInvoice->getStatusCode());
        [$foreignIssue] = $this->api('POST', '/payment-references', $this->otherToken, ['guarantor_id' => $this->parent['person_id']], $this->school);
        self::assertSame(403, $foreignIssue->getStatusCode());
        // Rejeu de la clé du premier parent avec le même corps : jamais sa réponse.
        [$replay] = $this->api('POST', '/payments', $this->otherToken, ['channel' => 'orange_money', 'amount_xaf' => 50000, 'family_reference' => $reference, 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'portail-parent-0001']);
        self::assertContains($replay->getStatusCode(), [404, 422]);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testLeLibreServiceNeDonneAucunAutreDroitDeFacturation(): void
    {
        $reference = (string) $this->ok('POST', '/payment-references', $this->parentToken, ['guarantor_id' => $this->parent['person_id']], $this->school, [], 201)['data']['reference'];

        [$cash] = $this->api('POST', '/payments', $this->parentToken, ['channel' => 'cash', 'amount_xaf' => 1000, 'family_reference' => $reference], $this->school, ['Idempotency-Key' => 'portail-cash-0001']);
        self::assertSame(403, $cash->getStatusCode(), 'Espèces : réservé au guichet.');
        [$forged] = $this->api('POST', '/payments', $this->parentToken, ['channel' => 'orange_money', 'amount_xaf' => 1000, 'family_reference' => $reference, 'external_reference' => 'OM-FAUX', 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'portail-faux-0001']);
        self::assertSame(403, $forged->getStatusCode(), 'Une référence d\'opérateur fournie par le payeur confirmerait le paiement.');
        foreach ([['GET', '/payments'], ['GET', '/invoices'], ['GET', '/invoices/' . $this->invoiceId], ['GET', '/billing/families/' . $reference . '/balance'], ['GET', '/payments/export']] as [$method, $path]) {
            [$response] = $this->api($method, $path, $this->parentToken, null, $this->school);
            self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
        }
        [$receipt] = $this->api('POST', '/cash/receipts', $this->parentToken, ['amount_xaf' => 1000, 'family_reference' => $reference], $this->school, ['Idempotency-Key' => 'portail-caisse-0001']);
        self::assertSame(403, $receipt->getStatusCode());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }
}
