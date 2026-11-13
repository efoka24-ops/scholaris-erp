<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Tests\Support\Billing\BillingTestCase;

/** Remise de fratrie inter-établissements et paiement multi-enfants multi-établissements en une opération. */
final class FamilyPaymentTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid('e1');
        $this->seedIpesGrid('e2');
    }

    public function testRemiseFratrieQuandLeFrereEstAuCollege(): void
    {
        $aine = $this->invoiceFor('a-aine', 'g1', 'en-a', 'e2');
        $cadet = $this->invoiceFor('b-cadet', 'g1', 'en-b', 'e1');

        self::assertSame(0, $aine['discount_xaf']);
        self::assertSame(25000, $cadet['discount_xaf'], 'Remise fratrie (frère au Collège) -25 000');
        self::assertSame(475000, $cadet['net_xaf']);
        self::assertSame('sibling', $cadet['discounts'][0]['kind']);
        self::assertSame(25000, $cadet['discounts'][0]['amount_xaf']);
        // La remise ne touche que la pension, répartie sur ses 3 tranches.
        $tuition = array_values(array_filter($cadet['installments'], static fn (array $i): bool => $i['kind'] === 'tuition'));
        self::assertSame(25000, 450000 - array_sum(array_map(static fn (array $i): int => $i['amount_xaf'], $tuition)));
        self::assertSame(50000, array_values(array_filter($cadet['installments'], static fn (array $i): bool => $i['kind'] === 'registration'))[0]['amount_xaf']);
    }

    public function testRemiseDeFratrieParametrable(): void
    {
        $this->settings->values['billing.sibling_discount'] = ['mode' => 'percent', 'value' => 10];
        $this->invoiceFor('a-aine', 'g1', 'en-a', 'e2');
        $cadet = $this->invoiceFor('b-cadet', 'g1', 'en-b', 'e1');

        self::assertSame(45000, $cadet['discount_xaf']);

        $this->settings->values['billing.sibling_discount'] = ['mode' => 'fixed', 'value' => 0];
        $third = $this->invoiceFor('c-benjamin', 'g1', 'en-c', 'e1');
        self::assertSame(0, $third['discount_xaf'], 'Valeur 0 : remise désactivée.');
    }

    public function testUnPaiementUniquePayeLesDeuxEnfantsDeDeuxEtablissements(): void
    {
        $this->invoiceFor('a-aine', 'g1', 'en-a', 'e2');
        $this->invoiceFor('b-cadet', 'g1', 'en-b', 'e1');
        $this->reference('g1');
        $this->openCash();
        $this->clock->set('2026-10-20T09:00:00+01:00');

        // Dûs en retard : inscription 50 000 de chaque enfant et tranche 1 (15/10) de chacun.
        [, $json] = $this->pay('cashier', ['amount_xaf' => 300000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        $allocations = $json['data']['allocations'];
        self::assertSame(300000, array_sum(array_map(static fn (array $a): int => $a['amount_xaf'], $allocations)));
        self::assertEqualsCanonicalizing(['e1', 'e2'], array_values(array_unique(array_map(static fn (array $a): string => $a['establishment_id'], $allocations))));
        self::assertCount(1, $this->rows('SELECT id FROM receipts'), 'Une seule opération, un seul reçu.');

        [, $balance] = $this->api('GET', '/billing/families/FAM-0001/balance', 'reader');
        self::assertSame(1000000 - 25000 - 300000, $balance['data']['total_due_xaf']);
        self::assertCount(2, $balance['data']['children']);
        self::assertSame('Foka', explode(' ', $balance['data']['children'][0]['name'])[1]);
        foreach ($balance['data']['children'] as $child) {
            self::assertNotEmpty($child['establishments']);
        }
    }

    public function testExonerationEnAttenteDeLaValidationDUneAutrePersonne(): void
    {
        $invoice = $this->invoiceFor('yann', 'g1', 'en1', 'e1', [['kind' => 'waiver', 'mode' => 'percent', 'value' => 100, 'reason' => 'Orphelin de père']]);

        self::assertSame(0, $invoice['discount_xaf']);
        self::assertSame('pending', $invoice['discounts'][0]['status']);

        [$self] = $this->api('POST', '/discounts/' . $invoice['discounts'][0]['id'] . '/approve', 'manager');
        self::assertSame(403, $self->getStatusCode(), 'Le demandeur ne peut pas valider sa propre exonération.');

        $this->permissions->grant('supervisor', 'billing.manage');
        [$ok] = $this->api('POST', '/discounts/' . $invoice['discounts'][0]['id'] . '/approve', 'supervisor');
        self::assertSame(200, $ok->getStatusCode());
        [, $after] = $this->api('GET', '/invoices/' . $invoice['id'], 'reader');
        self::assertSame(450000, $after['data']['discount_xaf'], 'Exonération de la pension seulement.');
        self::assertSame(50000, $after['data']['net_xaf']);
    }

    public function testRemiseAjouteeApresUnPaiementEstRepartieSurLesTranchesRestantes(): void
    {
        $invoice = $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->openCash();
        $this->clock->set('2026-10-10T09:00:00+01:00');
        $this->pay('cashier', ['amount_xaf' => 200000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        // Inscription 50 000 + tranche 1 de 150 000 réglées ; bourse de 20 % = 90 000 sur les 2 tranches restantes.
        [$response, $json] = $this->api('POST', '/invoices/' . $invoice['id'] . '/discounts', 'manager', ['kind' => 'merit', 'mode' => 'percent', 'value' => 20, 'reason' => 'Mérite']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $remaining = array_values(array_filter($json['data']['installments'], static fn (array $i): bool => $i['paid_xaf'] === 0));
        self::assertSame([105000, 105000], array_map(static fn (array $i): int => $i['amount_xaf'], $remaining));
        self::assertSame(90000, $json['data']['discount_xaf']);
    }
}
