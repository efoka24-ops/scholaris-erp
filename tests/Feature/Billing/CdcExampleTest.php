<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Scholaris\Modules\Socle\Billing\Application\ReminderService;
use Scholaris\Modules\Socle\Billing\Contracts\DebtorDirectory;
use Tests\Support\Billing\BillingTestCase;

/**
 * Exemple chiffré du CDC IPES : inscription 50 000 + pension 450 000 avec bourse de mérite de 20 % sur la pension,
 * soit 360 000 en 3 tranches de 120 000 (15 octobre, 15 janvier, 15 avril).
 */
final class CdcExampleTest extends BillingTestCase
{
    protected function setUp(): void
    {
        $this->bootBilling();
        $this->seedIpesGrid();
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function installments(string $invoiceId): array
    {
        [, $json] = $this->api('GET', '/invoices/' . $invoiceId, 'reader');

        return array_map(static fn (array $i): array => [$i['due_on'], $i['amount_xaf'], $i['paid_xaf']], $json['data']['installments']);
    }

    public function testExempleChiffreComplet(): void
    {
        $invoice = $this->invoiceFor('yann', 'g1', 'en1', 'e1', [['kind' => 'merit', 'mode' => 'percent', 'value' => 20, 'reason' => 'Bourse de mérite']]);

        // La bourse s'applique à la pension seulement : 450 000 - 90 000 = 360 000, en 3 tranches de 120 000.
        self::assertSame(500000, $invoice['gross_xaf']);
        self::assertSame(90000, $invoice['discount_xaf']);
        self::assertSame(410000, $invoice['net_xaf']);
        self::assertSame(
            [['2026-10-08', 50000, 0], ['2026-10-15', 120000, 0], ['2027-01-15', 120000, 0], ['2027-04-15', 120000, 0]],
            $this->installments($invoice['id']),
        );

        $reference = $this->reference('g1');
        self::assertSame('FAM-0001', $reference['reference']);
        self::assertCount(1, $this->messenger->of('billing.family_reference'));

        $this->openCash();

        // 10 octobre : 170 000 = inscription 50 000 + tranche 1 de 120 000.
        $this->clock->set('2026-10-10T10:00:00+01:00');
        [$response, $json] = $this->pay('cashier', ['amount_xaf' => 170000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('R-IPES-26-00001', $json['data']['receipt']['number']);
        self::assertSame(
            [50000, 120000],
            array_map(static fn (array $a): int => $a['amount_xaf'], $json['data']['allocations']),
        );
        self::assertSame('registration_overdue_upcoming@1', $json['data']['allocations'][0]['rule_version']);
        self::assertSame(
            [['2026-10-08', 50000, 50000], ['2026-10-15', 120000, 120000], ['2027-01-15', 120000, 0], ['2027-04-15', 120000, 0]],
            $this->installments($invoice['id']),
        );

        $sms = $this->messenger->of('billing.receipt');
        self::assertCount(1, $sms);
        self::assertSame('g1', $sms[0]['person']);
        self::assertSame(['receipt_number' => 'R-IPES-26-00001', 'amount' => 170000, 'balance' => 240000], $sms[0]['variables']);

        // 16 janvier : la tranche 2 est en retard depuis hier, relance J+1.
        $this->clock->set('2027-01-16T08:00:00+01:00');
        $debtors = $this->container()->get(DebtorDirectory::class);
        self::assertSame(['yann'], $debtors->learnerIdsInArrears('t1', 'e1'));
        self::assertSame(240000, $debtors->balanceXaf('t1', 'e1', 'yann'));

        $result = $this->container()->get(ReminderService::class)->run();
        self::assertSame(1, $result['sent']);
        $reminders = $this->messenger->of('billing.reminder');
        self::assertCount(1, $reminders);
        self::assertSame(['amount' => 120000, 'due_on' => '2027-01-15', 'label' => 'Pension · tranche 2', 'offset' => 1], $reminders[0]['variables']);
        self::assertSame(['sent' => 0, 'skipped' => 1], $this->container()->get(ReminderService::class)->run(), 'Une relance n\'est jamais envoyée deux fois.');

        // 20 janvier : 100 000 vont à la tranche 2 (en retard), puis 20 000 le 2 février la soldent.
        $this->clock->set('2027-01-20T10:00:00+01:00');
        [, $json] = $this->pay('cashier', ['amount_xaf' => 100000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame('R-IPES-27-00001', $json['data']['receipt']['number'], 'La numérotation repart à 1 chaque année.');
        $this->clock->set('2027-02-02T10:00:00+01:00');
        $this->pay('cashier', ['amount_xaf' => 20000, 'family_reference' => 'FAM-0001'], '/cash/receipts');

        self::assertSame(
            [['2026-10-08', 50000, 50000], ['2026-10-15', 120000, 120000], ['2027-01-15', 120000, 120000], ['2027-04-15', 120000, 0]],
            $this->installments($invoice['id']),
        );
        self::assertSame([], $debtors->learnerIdsInArrears('t1', 'e1'));
        self::assertSame(120000, $debtors->balanceXaf('t1', 'e1', 'yann'));

        [, $balance] = $this->api('GET', '/billing/families/FAM-0001/balance', 'reader');
        self::assertSame(120000, $balance['data']['total_due_xaf']);
        self::assertSame(0, $balance['data']['overdue_xaf']);
        self::assertSame('2027-04-15', $balance['data']['next_due_on']);
    }

    public function testSurplusConserveEnCreditPuisAffecteALaFactureSuivante(): void
    {
        $this->clock->set('2026-10-10T10:00:00+01:00');
        $this->invoiceFor('yann', 'g1', 'en1');
        $this->reference('g1');
        $this->openCash();

        [, $json] = $this->pay('cashier', ['amount_xaf' => 600000, 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(500000, array_sum(array_map(static fn (array $a): int => $a['amount_xaf'], $json['data']['allocations'])));
        self::assertSame(100000, $json['data']['credit_xaf']);

        [, $balance] = $this->api('GET', '/billing/families/FAM-0001/balance', 'reader');
        self::assertSame(100000, $balance['data']['credit_xaf']);

        // Un second enfant est inscrit : le crédit de la famille couvre aussitôt son inscription.
        $second = $this->invoiceFor('nadia', 'g1', 'en2');
        self::assertSame(100000, $second['paid_xaf']);
    }
}
