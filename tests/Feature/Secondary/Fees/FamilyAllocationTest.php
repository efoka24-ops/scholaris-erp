<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Fees;

use Scholaris\Modules\Apps\Secondary\Fees\Domain\FeesDefaults;
use Tests\Support\Billing\BillingTestCase;

/**
 * T945, TST-07 (RG-FIN-4) : exemple de frais du privé du cahier des charges (jeu `example_cdc`, valeurs FICTIVES, non officielles),
 * de bout en bout sur le socle Billing avec les préréglages du secondaire (FeesDefaults::PRIVATE_PRESETS, posés par
 * `POST /secondary/fees/private/setup`) et la nature `apee` déclarée par le secondaire. Lycée privé, 2nde, deux enfants d'une famille :
 * inscription 3 500, pension 150 000 (60 000 / 50 000 / 40 000), fratrie 10 % sur la pension, APEE 10 000. Paiement familial de
 * 120 000 le 25 septembre : inscriptions 7 000, APEE 20 000, 93 000 sur les tranches 1 ; reste dû au 30 septembre : 21 000.
 */
final class FamilyAllocationTest extends BillingTestCase
{
    /** @return array<string, int> */
    private const EXAMPLE_CDC = ['registration' => 3500, 'tuition' => 150000, 'apee' => 10000, 'sibling_percent' => 10, 'payment' => 120000];

    /** @param list<array{due_on: string, amount: int}> $terms */
    private function fee(string $kind, string $name, int $amount, array $terms): void
    {
        [$response, $json] = $this->api('POST', '/fee-items', 'manager', ['kind' => $kind, 'name' => $name, 'amount_xaf' => $amount]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        [$schedule] = $this->api('POST', '/fee-schedules', 'manager', ['fee_item_id' => $json['data']['id'], 'academic_year_id' => 'ay1', 'amount_xaf' => $amount, 'terms' => $terms]);
        self::assertSame(201, $schedule->getStatusCode(), (string) $schedule->getBody());
    }

    public function testRepartitionDuPaiementFamilialDeLExempleDuCahierDesCharges(): void
    {
        $this->bootBilling(FeesDefaults::PRIVATE_PRESETS + ['billing.sibling_discount' => ['mode' => 'percent', 'value' => self::EXAMPLE_CDC['sibling_percent']]]);
        $this->clock->set('2026-09-01T08:00:00+01:00');
        $this->fee('registration', 'Inscription', self::EXAMPLE_CDC['registration'], [['due_on' => '2026-09-01', 'amount' => self::EXAMPLE_CDC['registration']]]);
        $this->fee('tuition', 'Pension', self::EXAMPLE_CDC['tuition'], [
            ['due_on' => '2026-09-30', 'amount' => 60000], ['due_on' => '2027-01-15', 'amount' => 50000], ['due_on' => '2027-03-15', 'amount' => 40000],
        ]);
        $this->fee('apee', 'APEE', self::EXAMPLE_CDC['apee'], [['due_on' => '2026-09-01', 'amount' => self::EXAMPLE_CDC['apee']]]);

        $first = $this->invoiceFor('kid-a', 'g1', 'en-a');
        $second = $this->invoiceFor('kid-b', 'g1', 'en-b');
        self::assertSame(163500, (int) $first['net_xaf'], 'Total de l\'année, enfant 1.');
        self::assertSame(148500, (int) $second['net_xaf'], 'Total de l\'année, enfant 2 (fratrie 10 % sur la pension).');
        $tranches = array_column($this->rows("SELECT amount_xaf, due_on FROM installments WHERE kind = 'tuition' AND learner_id = 'kid-b' ORDER BY due_on"), 'amount_xaf');
        self::assertSame([54000, 45000, 36000], array_map('intval', $tranches));

        $this->reference('g1');
        $this->openCash();
        $this->clock->set('2026-09-25T09:00:00+01:00');
        [$response] = $this->pay('cashier', ['amount_xaf' => self::EXAMPLE_CDC['payment'], 'family_reference' => 'FAM-0001'], '/cash/receipts');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $byKind = [];
        foreach ($this->rows('SELECT i.kind, i.due_on, a.amount_xaf, a.rule_version FROM allocations a JOIN installments i ON i.id = a.installment_id') as $row) {
            $key = $row['kind'] === 'tuition' ? 'tuition:' . $row['due_on'] : (string) $row['kind'];
            $byKind[$key] = ($byKind[$key] ?? 0) + (int) $row['amount_xaf'];
            self::assertSame('kind_priority@1', $row['rule_version']);
        }
        ksort($byKind);
        self::assertSame(['apee' => 20000, 'registration' => 7000, 'tuition:2026-09-30' => 93000], $byKind);

        $dueBy30 = 0;
        foreach ($this->rows("SELECT amount_xaf, paid_xaf FROM installments WHERE due_on <= '2026-09-30'") as $row) {
            $dueBy30 += (int) $row['amount_xaf'] - (int) $row['paid_xaf'];
        }
        self::assertSame(21000, $dueBy30, 'Reste dû au 30 septembre.');
    }
}
