<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Webhook de l'agrégateur : signature HMAC, rejeu ignoré, jamais de crédit en double. */
final class PaymentWebhookTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string, mixed>}
     */
    private function webhook(array $event, ?string $signature = null, string $provider = 'orange_money'): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $response = $this->call('POST', '/api/v1/webhooks/payments/' . $provider, [
            'Content-Type' => 'application/json',
            'X-Signature' => $signature ?? hash_hmac('sha256', $body, self::SECRET),
        ], $body);

        return [$response, (array) json_decode((string) $response->getBody(), true)];
    }

    /** @return array<string, mixed> */
    private function event(string $id = 'evt-1', int $amount = 50000): array
    {
        return ['event_id' => $id, 'type' => 'payment.succeeded', 'amount' => $amount, 'provider_reference' => 'OM-' . $id, 'reference' => 'FAM-0001', 'phone' => '+237670000000'];
    }

    public function testSignatureAbsenteOuInvalideRefusee(): void
    {
        [$none] = $this->webhook($this->event(), '');
        [$bad] = $this->webhook($this->event(), str_repeat('0', 64));

        self::assertSame(401, $none->getStatusCode());
        self::assertSame(401, $bad->getStatusCode());
        self::assertCount(0, $this->rows('SELECT id FROM payments'));
        self::assertCount(0, $this->rows('SELECT id FROM webhook_events'));
    }

    public function testEvenementSigneCreditePuisLeRejeuEstIgnore(): void
    {
        [$first, $one] = $this->webhook($this->event());
        [$replay, $two] = $this->webhook($this->event());

        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('confirmed', $one['data']['status']);
        self::assertSame(200, $replay->getStatusCode());
        self::assertSame('duplicate', $two['data']['status']);
        self::assertCount(1, $this->rows('SELECT id FROM payments'));
        self::assertCount(1, $this->rows('SELECT id FROM receipts'));
        self::assertSame(50000, (int) $this->rows('SELECT SUM(amount_xaf) AS s FROM allocations')[0]['s']);

        $payment = $this->rows('SELECT * FROM payments')[0];
        self::assertSame('confirmed', $payment['status']);
        self::assertNotNull($payment['reconciled_at'], 'Référence famille reconnue : le paiement est rapproché.');
        self::assertCount(1, $this->messenger->of('billing.receipt'));
    }

    public function testMemePaiementSousUnAutreEvenementNEstPasCreditedeuxFois(): void
    {
        $this->webhook($this->event('evt-1'));
        $again = $this->event('evt-2');
        $again['provider_reference'] = 'OM-evt-1';
        [, $json] = $this->webhook($again);

        self::assertSame('already_recorded', $json['data']['status']);
        self::assertCount(1, $this->rows('SELECT id FROM payments'));
    }

    public function testPaiementInitieParLeGuichetEstConfirmeParLeWebhook(): void
    {
        $this->openCash();
        [$response, $json] = $this->pay('cashier', ['channel' => 'orange_money', 'amount_xaf' => 30000, 'family_reference' => 'FAM-0001', 'payer_phone' => '+237670000000']);
        self::assertSame(202, $response->getStatusCode());
        $providerReference = $json['data']['external_reference'];
        self::assertNotNull($providerReference);

        [, $confirm] = $this->webhook(['event_id' => 'evt-a', 'type' => 'payment.succeeded', 'amount' => 30000, 'provider_reference' => $providerReference]);
        self::assertSame('confirmed', $confirm['data']['status']);
        self::assertSame('confirmed', $this->rows('SELECT status FROM payments')[0]['status']);
        self::assertCount(1, $this->rows('SELECT id FROM receipts'));

        // Une seconde livraison (autre event_id) ne rejoue pas le crédit.
        [, $second] = $this->webhook(['event_id' => 'evt-b', 'type' => 'payment.succeeded', 'amount' => 30000, 'provider_reference' => $providerReference]);
        self::assertSame('unmatched', $second['data']['status']);
        self::assertSame(30000, (int) $this->rows('SELECT SUM(amount_xaf) AS s FROM allocations')[0]['s']);
    }

    public function testMontantDifferentOuEchecNeCreditentRien(): void
    {
        $this->openCash();
        [, $json] = $this->pay('cashier', ['channel' => 'mtn_momo', 'amount_xaf' => 30000, 'family_reference' => 'FAM-0001', 'payer_phone' => '+237670000000']);
        $ref = $json['data']['external_reference'];

        [, $mismatch] = $this->webhook(['event_id' => 'evt-m', 'type' => 'payment.succeeded', 'amount' => 29000, 'provider_reference' => $ref], provider: 'mtn_momo');
        self::assertSame('amount_mismatch', $mismatch['data']['status']);
        self::assertSame('pending', $this->rows('SELECT status FROM payments')[0]['status']);

        [, $failed] = $this->webhook(['event_id' => 'evt-f', 'type' => 'payment.failed', 'amount' => 30000, 'provider_reference' => $ref], provider: 'mtn_momo');
        self::assertSame('failed', $failed['data']['status']);
        self::assertSame('failed', $this->rows('SELECT status FROM payments')[0]['status']);
        self::assertCount(0, $this->rows('SELECT id FROM receipts'));
    }

    public function testReferenceInconnueEstConserveeSansRienCrediter(): void
    {
        $event = $this->event('evt-x');
        $event['reference'] = 'FAM-9999';
        [$response, $json] = $this->webhook($event);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('unmatched', $json['data']['status']);
        self::assertCount(0, $this->rows('SELECT id FROM payments'));
        self::assertCount(1, $this->rows('SELECT id FROM webhook_events'));
    }

    public function testCorpsInvalideRefuse(): void
    {
        [$response] = $this->webhook(['event_id' => 'evt-z', 'type' => 'payment.succeeded', 'amount' => 10.5, 'provider_reference' => 'OM-z']);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->rows('SELECT id FROM webhook_events'));
    }
}
