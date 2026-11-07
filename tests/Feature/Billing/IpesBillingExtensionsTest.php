<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeInvoice;
use Scholaris\Modules\Socle\Billing\Contracts\ChargeLine;
use Scholaris\Modules\Socle\Billing\Contracts\InvoiceIssuer;
use Scholaris\Modules\Socle\Billing\Contracts\InvoicePayer;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentOrder;
use Scholaris\Modules\Socle\Billing\Contracts\PaymentRequester;
use Tests\Support\Billing\BillingTestCase;

/**
 * Extensions IPES de Billing : D4 (contrats InvoiceIssuer, PaymentRequester, candidat sans inscription), S10 (code stable et
 * types de frais d'application), S11 (plafond des remises), S13 (facture au nom d'un tiers). Sans paramètre ni enregistrement,
 * les réponses restent celles du socle.
 */
final class IpesBillingExtensionsTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling([], ['billing.fee_kinds.higher' => ['application_fee', 'national_exam', 'Invalide!']]);
    }

    private function issuer(): InvoiceIssuer
    {
        return $this->container()->get(InvoiceIssuer::class);
    }

    private function requester(): PaymentRequester
    {
        return $this->container()->get(PaymentRequester::class);
    }

    /** @param array<string, mixed> $body */
    private function feeItem(array $body): array
    {
        [$response, $json] = $this->api('POST', '/fee-items', 'manager', $body);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    public function testSansCodeLaReponseDuFraisResteCelleDuSocle(): void
    {
        $item = $this->feeItem(['kind' => 'tuition', 'name' => 'Pension', 'amount_xaf' => 450000]);
        self::assertSame(['id', 'establishment_id', 'kind', 'name', 'amount_xaf', 'active', 'created_at', 'updated_at'], array_keys($item));
        [, $list] = $this->api('GET', '/fee-items', 'reader');
        self::assertSame(array_keys($item), array_keys($list['data'][0]));

        $this->seedIpesGrid();

        $invoice = $this->invoiceFor('yann', 'g1', 'en1');
        self::assertArrayNotHasKey('payer', $invoice);
        self::assertArrayNotHasKey('invoice_kind', $invoice);
    }

    public function testCodeStableUniqueEtTypeEnregistreParUneApplication(): void
    {
        $item = $this->feeItem(['kind' => 'application_fee', 'code' => 'dossier', 'name' => 'Frais de dossier', 'amount_xaf' => 15000]);
        self::assertSame('dossier', $item['code']);
        self::assertSame('application_fee', $item['kind']);

        // Un type non enregistré (ou mal formé) est refusé.
        [$bad] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'Invalide!', 'name' => 'X', 'amount_xaf' => 1]);
        self::assertSame(422, $bad->getStatusCode());
        [$unknown] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'defense', 'name' => 'X', 'amount_xaf' => 1]);
        self::assertSame(422, $unknown->getStatusCode());

        // Code unique par établissement, mais réutilisable dans un autre.
        [$dup] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'other', 'code' => 'dossier', 'name' => 'Doublon', 'amount_xaf' => 1]);
        self::assertSame(409, $dup->getStatusCode());
        [$other] = $this->api('POST', '/fee-items', 'manager', ['kind' => 'other', 'code' => 'dossier', 'name' => 'Ailleurs', 'amount_xaf' => 1], 't1', 'e2');
        self::assertSame(201, $other->getStatusCode());

        // Le code est stable : on peut le renseigner une fois, jamais le changer.
        [$change] = $this->api('PATCH', '/fee-items/' . $item['id'], 'manager', ['code' => 'autre']);
        self::assertSame(409, $change->getStatusCode());
        $plain = $this->feeItem(['kind' => 'other', 'name' => 'Carte', 'amount_xaf' => 2000]);
        [$set, $json] = $this->api('PATCH', '/fee-items/' . $plain['id'], 'manager', ['code' => 'carte']);
        self::assertSame(200, $set->getStatusCode());
        self::assertSame('carte', $json['data']['code']);

        [, $list] = $this->api('GET', '/fee-items?filter[code]=dossier', 'reader');
        self::assertSame(['dossier'], array_column($list['data'], 'code'));
    }

    public function testExempleCdcParLeContratAvecTiersPayeur(): void
    {
        $this->seedIpesGrid();
        $this->people->add('t1', 'yann', 'Yann', 'Foka');
        $this->enrollments->add('t1', 'en1', 'yann', 'e1');

        $summary = $this->issuer()->forEnrollment('t1', 'e1', 'manager', 'en1', null, null, [
            ['kind' => 'merit', 'mode' => 'percent', 'value' => 20, 'reason' => 'Bourse de mérite'],
        ], new InvoicePayer(InvoicePayer::ORGANISATION, 'Société Alpha SA', 'CONV-2026-01'));

        self::assertSame(410000, $summary->netXaf);
        self::assertSame(90000, $summary->discountXaf);
        self::assertSame('enrollment', $summary->kind);
        self::assertSame('en1', $summary->enrollmentId);
        self::assertSame('Société Alpha SA', $summary->payer?->name);
        self::assertSame([50000, 120000, 120000, 120000], array_column($summary->installments, 'amount_xaf'));

        [, $json] = $this->api('GET', '/invoices/' . $summary->id, 'reader');
        self::assertSame(['kind' => 'organisation', 'name' => 'Société Alpha SA', 'reference' => 'CONV-2026-01'], $json['data']['payer']);
    }

    public function testFactureAuNomDUnTiersParLApi(): void
    {
        $this->seedIpesGrid();
        $this->people->add('t1', 'yann', 'Yann', 'Foka');
        $this->enrollments->add('t1', 'en1', 'yann', 'e1');

        [$bad] = $this->api('POST', '/invoices', 'manager', ['enrollment_id' => 'en1', 'payer' => ['kind' => 'ministere', 'name' => 'X']]);
        self::assertSame(422, $bad->getStatusCode());
        [$response, $json] = $this->api('POST', '/invoices', 'manager', ['enrollment_id' => 'en1', 'payer' => ['kind' => 'person', 'name' => 'Oncle Paul']]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['kind' => 'person', 'name' => 'Oncle Paul', 'reference' => null], $json['data']['payer']);
    }

    public function testCandidatSansInscriptionFactureEtPaiementMobileMoney(): void
    {
        $this->feeItem(['kind' => 'application_fee', 'code' => 'dossier', 'name' => 'Frais de dossier', 'amount_xaf' => 15000]);
        $this->people->add('t1', 'cand1', 'Awa', 'Njoya');

        $order = new ChargeInvoice('t1', 'e1', 'manager', 'cand1', 'ay1', 'cand-0001', [new ChargeLine('dossier', '2026-10-20')]);
        $invoice = $this->issuer()->forCharges($order);
        self::assertSame('charges', $invoice->kind);
        self::assertNull($invoice->enrollmentId);
        self::assertSame('cand-0001', $invoice->reference);
        self::assertSame(15000, $invoice->netXaf);
        self::assertSame('dossier', $invoice->lines[0]['fee_code']);
        self::assertSame(15000, $invoice->balanceForCode('dossier'));

        // Idempotence par référence ; même référence pour une autre personne : conflit.
        self::assertSame($invoice->id, $this->issuer()->forCharges($order)->id);
        self::assertSame($invoice->id, $this->issuer()->findByReference('t1', 'e1', 'cand-0001')?->id);
        try {
            $this->issuer()->forCharges(new ChargeInvoice('t1', 'e1', 'manager', 'autre', 'ay1', 'cand-0001', [new ChargeLine('dossier', '2026-10-20')]));
            self::fail('Conflit attendu.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }

        // Paiement mobile money avec référence opérateur : confirmé, reçu numéroté, facture soldée.
        $outcome = $this->requester()->request(new PaymentOrder('t1', 'e1', 'manager', $invoice->id, 15000, 'orange_money', 'cand-0001-paiement', '+237690000000', 'OM-778899'));
        self::assertSame('confirmed', $outcome->status);
        self::assertSame('R-IPES-26-00001', $outcome->receiptNumber);
        self::assertSame($invoice->id, $outcome->invoiceId);
        $paid = $this->issuer()->find('t1', 'e1', $invoice->id);
        self::assertSame(0, $paid?->balanceXaf);
        self::assertSame(0, $paid?->balanceForCode('dossier'));
        self::assertSame('paid', $paid?->status);

        // Rejeu : même paiement, aucun second crédit.
        $again = $this->requester()->request(new PaymentOrder('t1', 'e1', 'manager', $invoice->id, 15000, 'orange_money', 'cand-0001-paiement', '+237690000000', 'OM-778899'));
        self::assertTrue($again->replayed);
        self::assertSame($outcome->paymentId, $again->paymentId);
        self::assertSame('confirmed', $this->requester()->status('t1', 'e1', $outcome->paymentId)?->status);

        // Cloisonnement : un autre établissement ne voit ni la facture ni le paiement.
        self::assertNull($this->issuer()->find('t1', 'e2', $invoice->id));
        self::assertNull($this->requester()->status('t1', 'e2', $outcome->paymentId));
        self::assertNull($this->issuer()->find('t2', 'e1', $invoice->id));
    }

    public function testContratRefuseFraisInconnuCanalInconnuEtCaisseFermee(): void
    {
        $this->feeItem(['kind' => 'other', 'code' => 'carte', 'name' => 'Carte', 'amount_xaf' => 2000]);
        foreach ([
            fn () => $this->issuer()->forCharges(new ChargeInvoice('t1', 'e1', 'manager', 'c1', 'ay1', 'r1', [new ChargeLine('inconnu', '2026-10-20')])),
            fn () => $this->issuer()->forCharges(new ChargeInvoice('t1', 'e1', 'manager', 'c1', 'ay1', 'r1', [new ChargeLine('carte', '20-10-2026')])),
            fn () => $this->issuer()->forCharges(new ChargeInvoice('t1', 'e1', 'manager', 'c1', 'ay1', 'r 1', [new ChargeLine('carte', '2026-10-20')])),
        ] as $call) {
            try {
                $call();
                self::fail('Refus attendu.');
            } catch (HttpException $e) {
                self::assertSame(422, $e->status);
            }
        }

        $invoice = $this->issuer()->forCharges(new ChargeInvoice('t1', 'e1', 'manager', 'c1', 'ay1', 'r1', [new ChargeLine('carte', '2026-10-20', 2500, 'Carte d\'étudiant')]));
        self::assertSame(2500, $invoice->netXaf);
        self::assertSame('Carte d\'étudiant', $invoice->lines[0]['label']);
        try {
            $this->requester()->request(new PaymentOrder('t1', 'e1', 'cashier', $invoice->id, 2500, 'cheque', 'cle-paiement-01'));
            self::fail('Canal refusé attendu.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        try {
            $this->requester()->request(new PaymentOrder('t1', 'e1', 'cashier', $invoice->id, 2500, 'cash', 'cle-paiement-02'));
            self::fail('Caisse fermée attendue.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $this->openCash();
        $cash = $this->requester()->request(new PaymentOrder('t1', 'e1', 'cashier', $invoice->id, 2500, 'cash', 'cle-paiement-03'));
        self::assertSame('confirmed', $cash->status);
        self::assertNotNull($cash->receiptNumber);
    }

    public function testPlafondDesRemisesEtValidationAuDela(): void
    {
        $this->settings->values['billing.discount_cap_percent'] = 50;
        $this->settings->values['billing.discount_approval_percent'] = 30;
        $this->settings->values['billing.discount_applies_to'] = ['tuition'];
        $this->seedIpesGrid();
        $invoice = $this->invoiceFor('yann', 'g1', 'en1', 'e1', [['kind' => 'merit', 'mode' => 'percent', 'value' => 20, 'reason' => 'Bourse de mérite']]);
        self::assertSame(410000, $invoice['net_xaf']);

        // Remise sur l'inscription refusée (RG-FIN-1 paramétrée).
        [$registration, $json] = $this->api('POST', '/invoices/' . $invoice['id'] . '/discounts', 'manager', ['kind' => 'staff', 'mode' => 'fixed', 'value' => 5000, 'applies_to' => 'registration', 'reason' => 'Personnel']);
        self::assertSame(422, $registration->getStatusCode());
        self::assertArrayHasKey('applies_to', $json['errors']);

        // 20 % + 35 % = 55 % > plafond de 50 % : refus.
        [$over] = $this->api('POST', '/invoices/' . $invoice['id'] . '/discounts', 'manager', ['kind' => 'staff', 'mode' => 'percent', 'value' => 35, 'reason' => 'Personnel']);
        self::assertSame(422, $over->getStatusCode());

        // 20 % + 15 % = 35 % > 30 % : la remise attend la validation d'une autre personne.
        [$pending, $json] = $this->api('POST', '/invoices/' . $invoice['id'] . '/discounts', 'manager', ['kind' => 'staff', 'mode' => 'percent', 'value' => 15, 'reason' => 'Personnel']);
        self::assertSame(201, $pending->getStatusCode(), (string) $pending->getBody());
        $staff = array_values(array_filter($json['data']['discounts'], static fn (array $d): bool => $d['kind'] === 'staff'))[0];
        self::assertSame('pending', $staff['status']);
        self::assertSame(410000, $json['data']['net_xaf']);

        // Une remise en attente compte dans le cumul : 20 + 15 + 20 = 55 % > 50 %.
        [$stacked] = $this->api('POST', '/invoices/' . $invoice['id'] . '/discounts', 'manager', ['kind' => 'merit', 'mode' => 'percent', 'value' => 20, 'reason' => 'Mérite']);
        self::assertSame(422, $stacked->getStatusCode());

        $this->permissions->grant('supervisor', 'billing.manage');
        [$approved] = $this->api('POST', '/discounts/' . $staff['id'] . '/approve', 'supervisor');
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        [, $after] = $this->api('GET', '/invoices/' . $invoice['id'], 'reader');
        self::assertSame(342500, $after['data']['net_xaf']);
    }

    public function testSansParametreAucunPlafond(): void
    {
        $this->seedIpesGrid();
        $invoice = $this->invoiceFor('yann', 'g1', 'en1');
        [$full, $json] = $this->api('POST', '/invoices/' . $invoice['id'] . '/discounts', 'manager', ['kind' => 'staff', 'mode' => 'percent', 'value' => 100, 'applies_to' => 'registration', 'reason' => 'Personnel']);
        self::assertSame(201, $full->getStatusCode());
        self::assertSame('approved', $json['data']['discounts'][0]['status']);
    }
}
