<?php

declare(strict_types=1);

namespace Tests\E2E;

use Nyholm\Psr7\ServerRequest;
use Tests\E2E\Support\BuildsSchool;
use Tests\E2E\Support\RealStackTestCase;

/**
 * Parcours 2 (US4, US10) : grille de frais, facture d'inscription, référence famille, encaissement en espèces idempotent
 * avec reçu PDF, paiement mobile money par le simulateur confirmé par webhook signé, affectation aux tranches les plus
 * anciennes, solde famille, portail famille du garant, exports CSV et xlsx.
 */
final class BillingJourneyTest extends RealStackTestCase
{
    use BuildsSchool;

    protected function setUp(): void
    {
        $this->bootRealStack();
        $this->buildSchool();
    }

    public function testFacturationEncaissementMobileMoneyEtPortailFamille(): void
    {
        // Le garant a un compte parent : son compte est créé d'abord, sa fiche sert de garant.
        $parent = $this->provisionUser($this->admin, $this->school, 'Paul', 'Foka', 'parent');
        ['learner' => $learner, 'enrollment' => $enrollment] = $this->enrollLearner('Nadia', 'Foka', '2011-05-14', $parent['person_id']);
        $this->ok('POST', '/people/' . $learner . '/relations', $this->admin, ['related_person_id' => $parent['person_id'], 'kind' => 'parent'], $this->school);

        // Comptable : catalogue, grille en trois tranches, facture de l'inscription.
        $accountant = $this->provisionUser($this->admin, $this->school, 'Cora', 'Compta', 'comptable');
        $acc = $this->signIn($accountant['login'], $accountant['password']);
        $tuition = (string) $this->ok('POST', '/fee-items', $acc, ['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 300000], $this->school, [], 201)['data']['id'];
        $this->ok('POST', '/fee-schedules', $acc, [
            'fee_item_id' => $tuition, 'academic_year_id' => $this->year, 'amount_xaf' => 300000,
            'terms' => [['due_on' => '2026-10-15', 'amount' => 100000], ['due_on' => '2027-01-15', 'amount' => 100000], ['due_on' => '2027-04-15', 'amount' => 100000]],
        ], $this->school, [], 201);
        $invoice = $this->ok('POST', '/invoices', $acc, ['enrollment_id' => $enrollment], $this->school, [], 201)['data'];
        self::assertSame(300000, $invoice['net_xaf'] ?? $invoice['total_xaf'] ?? null, json_encode($invoice));
        $reference = (string) $this->ok('POST', '/payment-references', $acc, ['guarantor_id' => $parent['person_id']], $this->school, [], 201)['data']['reference'];

        // Caissier : ouverture de caisse, encaissement espèces avec Idempotency-Key, rejeu, reçu PDF.
        $cashier = $this->provisionUser($this->admin, $this->school, 'Cathy', 'Caisse', 'caissier');
        $cash = $this->signIn($cashier['login'], $cashier['password']);
        $this->ok('POST', '/cash-sessions', $cash, ['float_xaf' => 20000], $this->school, [], 201);
        $body = ['amount_xaf' => 120000, 'family_reference' => $reference];
        [$missingKey] = $this->api('POST', '/cash/receipts', $cash, $body, $this->school);
        self::assertSame(400, $missingKey->getStatusCode(), 'Idempotency-Key obligatoire.');
        $first = $this->ok('POST', '/cash/receipts', $cash, $body, $this->school, ['Idempotency-Key' => 'recette-cash-0001'], 201);
        [$replay, $replayJson] = $this->api('POST', '/cash/receipts', $cash, $body, $this->school, ['Idempotency-Key' => 'recette-cash-0001']);
        self::assertSame('true', $replay->getHeaderLine('Idempotent-Replayed'));
        self::assertSame($first['data']['id'], $replayJson['data']['id']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM payments')->fetchColumn());

        $receipts = $this->ok('GET', '/receipts', $cash, null, $this->school);
        self::assertCount(1, $receipts['data']);
        $receiptId = (string) $receipts['data'][0]['id'];
        [$pdf] = $this->api('GET', '/receipts/' . $receiptId . '/pdf', $cash, null, $this->school);
        self::assertSame(200, $pdf->getStatusCode(), (string) $pdf->getBody());
        self::assertStringStartsWith('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());

        // Affectation : la première tranche (la plus ancienne) est soldée, la deuxième entamée.
        $after = $this->ok('GET', '/invoices/' . $invoice['id'], $acc, null, $this->school)['data'];
        self::assertSame(180000, $after['balance_xaf'], json_encode($after));
        $installments = $after['installments'] ?? [];
        self::assertNotEmpty($installments, json_encode($after));
        self::assertSame([100000, 20000, 0], array_map(static fn (array $i): int => (int) $i['paid_xaf'], $installments));

        // Mobile money (simulateur) : demande en attente puis confirmation par webhook signé.
        $pending = $this->ok('POST', '/payments', $cash, ['channel' => 'orange_money', 'amount_xaf' => 80000, 'family_reference' => $reference, 'payer_phone' => '+237670000123'], $this->school, ['Idempotency-Key' => 'recette-momo-0001'], 202)['data'];
        self::assertSame('pending', $pending['status']);
        $providerRef = (string) ($pending['external_reference'] ?? $pending['provider_reference'] ?? '');
        self::assertNotSame('', $providerRef, json_encode($pending));
        $event = json_encode(['event_id' => 'evt-recette-1', 'type' => 'payment.succeeded', 'amount' => 80000, 'provider_reference' => $providerRef, 'reference' => $reference, 'phone' => '+237670000123'], JSON_THROW_ON_ERROR);
        [$hook, $hookJson] = $this->send(new ServerRequest('POST', '/api/v1/webhooks/payments/orange_money', [
            'Content-Type' => 'application/json', 'X-Signature' => hash_hmac('sha256', $event, self::WEBHOOK_SECRET),
        ], $event));
        self::assertSame(200, $hook->getStatusCode(), (string) $hook->getBody());
        self::assertSame('confirmed', $hookJson['data']['status'], json_encode($hookJson));
        // Rejeu du même événement : ignoré.
        [, $dup] = $this->send(new ServerRequest('POST', '/api/v1/webhooks/payments/orange_money', [
            'Content-Type' => 'application/json', 'X-Signature' => hash_hmac('sha256', $event, self::WEBHOOK_SECRET),
        ], $event));
        self::assertSame('duplicate', $dup['data']['status']);
        $confirmed = $this->ok('GET', '/payments/' . $pending['id'], $cash, null, $this->school)['data'];
        self::assertSame('confirmed', $confirmed['status']);
        self::assertSame(100000, $this->ok('GET', '/invoices/' . $invoice['id'], $acc, null, $this->school)['data']['balance_xaf']);
        self::assertCount(2, $this->ok('GET', '/receipts', $cash, null, $this->school)['data'], 'Un reçu par paiement confirmé.');

        // Solde famille, tous établissements.
        $balance = $this->ok('GET', '/billing/families/' . $reference . '/balance', $acc, null, $this->school)['data'];
        self::assertSame(100000, $balance['total_due_xaf'], json_encode($balance));

        // Exports : CSV et xlsx des factures et paiements.
        [$csv] = $this->api('GET', '/payments/export', $acc, null, $this->school);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringStartsWith('text/csv', $csv->getHeaderLine('Content-Type'));
        self::assertGreaterThanOrEqual(3, substr_count(trim((string) $csv->getBody()), "\n") + 1, 'En-tête et deux paiements.');
        [$xlsx] = $this->api('GET', '/invoices/export?format=xlsx', $acc, null, $this->school);
        self::assertSame(200, $xlsx->getStatusCode(), (string) $xlsx->getBody());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody(), 'Un classeur xlsx est une archive ZIP.');

        // Portail famille : le parent voit son enfant et le reste à payer.
        $parentToken = $this->signIn($parent['login'], $parent['password']);
        $portal = $this->ok('GET', '/portal/family', $parentToken);
        $encoded = json_encode($portal, JSON_THROW_ON_ERROR);
        self::assertStringContainsString($learner, $encoded, 'Le portail famille montre l\'enfant.');
        self::assertStringContainsString('100000', $encoded, 'Le portail famille montre le reste à payer.');
    }
}
