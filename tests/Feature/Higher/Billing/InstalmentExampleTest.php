<?php

declare(strict_types=1);

namespace Tests\Feature\Higher\Billing;

use Tests\Support\Higher\Billing\HigherFinanceTestCase;

/**
 * TST-08 (T242) : échéancier du CDC sur le Billing du socle. Inscription 50 000 + pension 450 000 en 3 tranches ; bourse de mérite
 * 20 % sur la pension (pension nette 360 000, tranches de 120 000 aux 15 octobre, 15 janvier, 15 avril) ; total dû 410 000.
 * Chronologie : 10 oct 170 000 Orange Money (inscription 50 000 + tranche 1 120 000) ; 16 janv tranche 2 en retard (NOT-09 J+1) ;
 * 20 janv 100 000 MTN MoMo (reste 20 000) ; 30 janv relance J+15 ; 2 fév 20 000 au guichet (à jour, tranche 3 restante 120 000).
 */
final class InstalmentExampleTest extends HigherFinanceTestCase
{
    protected function setUp(): void
    {
        $this->bootFinance();
        $this->seedFeeCatalog();
    }

    public function testEcheancierDuCdcDeBoutEnBout(): void
    {
        $grid = $this->cdcGrid();
        self::assertSame([
            ['due_on' => '2026-10-15', 'weight' => 1], ['due_on' => '2027-01-15', 'weight' => 1], ['due_on' => '2027-04-15', 'weight' => 1],
        ], $grid['terms']);

        $account = $this->openAccount();
        self::assertSame(500000, $account['net_xaf']);
        self::assertSame([50000, 150000, 150000, 150000], array_column($account['installments'], 'amount_xaf'));

        // Bourse de mérite : taux par défaut 20 %, demandée par un comptable, validée par un autre.
        [$requested, $scholarship] = $this->api('POST', '/higher/billing/accounts/' . $account['id'] . '/scholarships', 'comptable', self::T, self::E, [
            'scholarship_kind' => 'merit', 'reason' => 'Major de promotion au concours',
        ]);
        self::assertSame(201, $requested->getStatusCode(), (string) $requested->getBody());
        self::assertSame('pending', $scholarship['data']['status']);
        self::assertSame(90000, $scholarship['data']['amount_xaf']);
        [$self] = $this->api('POST', '/higher/billing/scholarships/' . $scholarship['data']['id'] . '/approve', 'comptable', self::T, self::E, []);
        self::assertSame(403, $self->getStatusCode(), 'Le demandeur ne valide pas sa propre bourse.');
        [$approved, $decided] = $this->api('POST', '/higher/billing/scholarships/' . $scholarship['data']['id'] . '/approve', 'comptable2', self::T, self::E, []);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame(90000, $decided['data']['amount_xaf']);

        $view = $this->account($account['id']);
        self::assertSame(410000, $view['net_xaf'], 'Total dû 410 000');
        self::assertSame(['2026-10-15', '2026-10-15', '2027-01-15', '2027-04-15'], array_column($view['installments'], 'due_on'));
        self::assertSame([50000, 120000, 120000, 120000], array_column($view['installments'], 'amount_xaf'));
        $pension = array_values(array_filter($view['ledger']['lines'], static fn (array $l): bool => $l['fee_code'] === 'hi.pension'));
        self::assertSame(360000, array_sum(array_column($pension, 'net_xaf')), 'Pension nette 360 000');
        self::assertSame(0, $view['ledger']['lines'][0]['discount_xaf'], 'Aucune remise sur l\'inscription');

        // 10 octobre : 170 000 Orange Money -> inscription 50 000 + tranche 1 120 000.
        $this->clock->set('2026-10-10T10:00:00+01:00');
        [$status, $paid] = $this->pay($account['id'], 'comptable', 170000, 'orange_money', 'tst08-om-170000', 'OM-TST08-1');
        self::assertSame(201, $status, json_encode($paid, JSON_THROW_ON_ERROR));
        self::assertSame('confirmed', $paid['data']['payment']['status']);
        self::assertNotNull($paid['data']['payment']['receipt_number'], 'Reçu DOC-27 du socle');
        self::assertSame([50000, 120000, 0, 0], array_column($paid['data']['account']['installments'], 'paid_xaf'));
        self::assertSame(240000, $paid['data']['account']['balance_xaf']);
        $received = $this->messenger->of('higher.not10.payment_received');
        self::assertCount(1, $received, 'NOT-10 paiement reçu');
        self::assertSame(['amount' => 170000, 'receipt_number' => $paid['data']['payment']['receipt_number'], 'balance' => 240000], $received[0]['variables']);

        // 8 janvier : relance J-7 (NOT-08) de la tranche 2.
        $this->clock->set('2027-01-08T08:00:00+01:00');
        $run = $this->runReminders();
        self::assertSame(['NOT-08'], array_column($run['reminders'], 'notification'));
        self::assertSame(120000, $run['reminders'][0]['remaining_xaf']);

        // 15 janvier : pas encore en retard (le retard commence le lendemain de la date limite).
        $this->clock->set('2027-01-15T18:00:00+01:00');
        self::assertTrue($this->account($account['id'])['up_to_date']);

        // 16 janvier : tranche 2 en retard, relance J+1 (NOT-09).
        $this->clock->set('2027-01-16T08:00:00+01:00');
        $view = $this->account($account['id']);
        self::assertFalse($view['up_to_date']);
        self::assertSame('overdue', $view['installments'][2]['status']);
        self::assertSame(120000, $view['ledger']['overdue_xaf']);
        $run = $this->runReminders();
        self::assertSame([['NOT-09', 1]], array_map(static fn (array $r): array => [$r['notification'], $r['offset']], $run['reminders']));
        self::assertSame('marie', $this->messenger->of('higher.not09.overdue')[0]['person']);

        // 20 janvier : 100 000 MTN MoMo -> tranche 2 (reste 20 000).
        $this->clock->set('2027-01-20T11:00:00+01:00');
        [$status, $paid] = $this->pay($account['id'], 'comptable', 100000, 'mtn_momo', 'tst08-momo-100000', 'MOMO-TST08-2');
        self::assertSame(201, $status);
        self::assertSame([50000, 120000, 100000, 0], array_column($paid['data']['account']['installments'], 'paid_xaf'));
        self::assertSame(20000, $paid['data']['account']['installments'][2]['remaining_xaf']);

        // 30 janvier : relance J+15 sur les 20 000 restants, une seule fois.
        $this->clock->set('2027-01-30T08:00:00+01:00');
        $run = $this->runReminders();
        self::assertSame([['NOT-09', 15, 20000]], array_map(static fn (array $r): array => [$r['notification'], $r['offset'], $r['remaining_xaf']], $run['reminders']));
        self::assertSame(1, $run['sent']);
        $again = $this->runReminders();
        self::assertSame(0, $again['sent']);
        self::assertSame(1, $again['duplicates']);
        self::assertCount(2, $this->messenger->of('higher.not09.overdue'));

        // 2 février : 20 000 au guichet -> tranche 2 soldée, étudiant à jour, tranche 3 restante 120 000.
        $this->clock->set('2027-02-02T09:00:00+01:00');
        [$opened] = $this->api('POST', '/cash-sessions', 'caissier', self::T, self::E, ['float_xaf' => 0]);
        self::assertSame(201, $opened->getStatusCode(), (string) $opened->getBody());
        [$status, $paid] = $this->pay($account['id'], 'caissier', 20000, 'cash', 'tst08-cash-20000');
        self::assertSame(201, $status, json_encode($paid, JSON_THROW_ON_ERROR));
        $final = $this->account($account['id']);
        self::assertTrue($final['up_to_date']);
        self::assertSame(['paid', 'paid', 'paid', 'pending'], array_column($final['installments'], 'status'));
        self::assertSame(120000, $final['installments'][3]['remaining_xaf']);
        self::assertSame(120000, $final['balance_xaf']);
        self::assertSame(290000, $final['paid_xaf']);
        self::assertCount(3, $final['receipts']);
        self::assertSame(['orange_money', 'mtn_momo', 'cash'], array_column($final['receipts'], 'channel'));
    }

    public function testRejouerUnPaiementNeCreditePasDeuxFois(): void
    {
        $this->cdcGrid();
        $account = $this->openAccount();
        $this->clock->set('2026-10-10T10:00:00+01:00');
        [$first, $one] = $this->pay($account['id'], 'comptable', 50000, 'orange_money', 'replay-key-0001', 'OM-REPLAY-1');
        [$second, $two] = $this->pay($account['id'], 'comptable', 50000, 'orange_money', 'replay-key-0001', 'OM-REPLAY-1');
        self::assertSame([201, 201], [$first, $second]);
        self::assertTrue($two['data']['payment']['replayed']);
        self::assertSame($one['data']['payment']['id'], $two['data']['payment']['id']);
        self::assertSame(450000, $this->account($account['id'])['balance_xaf']);
        self::assertCount(1, $this->messenger->of('higher.not10.payment_received'));
    }

    /** @return array<string, mixed> */
    private function account(string $id): array
    {
        [$response, $json] = $this->api('GET', '/higher/billing/accounts/' . $id, 'comptable', self::T, self::E);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }

    /** @return array<string, mixed> */
    private function runReminders(): array
    {
        [$response, $json] = $this->api('POST', '/higher/notifications/reminders/run', 'comptable', self::T, self::E, []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $json['data'];
    }
}
