<?php

declare(strict_types=1);

namespace Tests\Feature\Nursery\Services;

use Tests\Feature\Nursery\Pickup\PickupTestCase;

/** EX-MAT-29, EX-MAT-32 (MQ-22) : services garderie, cantine, transport ; facturation une fois par cycle ; présence ; tableau de bord. */
final class ServicesTest extends PickupTestCase
{
    protected function setUp(): void
    {
        $this->bootPickup([], '2026-10-21T10:00:00+01:00');
        $this->grant(self::TA, self::DIRECTOR, self::EA, 'nursery.service.manage');
        $this->grant(self::TA, 'cantine-a', self::EA, 'nursery.service.attend');
        $this->grant(self::TA, 'educatrice-a', self::EA, 'nursery.service.attend', $this->sectionA);
    }

    /** @return array<string, mixed> */
    private function plan(string $cycle = 'month', string $fee = 'FEE-CANTINE'): array
    {
        return $this->ok('POST', '/nursery/services/plans', ['kind' => 'canteen', 'label' => 'Cantine (exemple)', 'fee_item_id' => $fee, 'billing_cycle' => $cycle]);
    }

    public function testAbonnementFactureUneSeuleFoisParCycle(): void
    {
        $plan = $this->plan();
        $sub = $this->ok('POST', '/nursery/services/subscriptions', ['learner_id' => $this->ada['child'], 'plan_id' => $plan['id'], 'starts_on' => '2026-10-01']);
        self::assertSame(409, $this->code('POST', '/nursery/services/subscriptions', ['learner_id' => $this->ada['child'], 'plan_id' => $plan['id']]), 'Pas deux abonnements actifs.');
        $invoice = $this->ok('POST', '/nursery/services/subscriptions/' . $sub['id'] . '/invoice', ['on' => '2026-10-21']);
        self::assertSame('2026-10', $invoice['cycle']);
        self::assertSame(15000, $invoice['amount_xaf'], 'Montant du catalogue du socle, jamais saisi ici.');
        self::assertFalse($invoice['already_invoiced']);
        self::assertTrue($this->ok('POST', '/nursery/services/subscriptions/' . $sub['id'] . '/invoice', ['on' => '2026-10-28'])['already_invoiced']);
        self::assertCount(1, $this->world->billing->charges);
        self::assertSame('2026-10-01', array_values($this->world->billing->charges)[0]->lines[0]->dueOn);
        $batch = $this->ok('POST', '/nursery/services/invoices', ['on' => '2026-11-03']);
        self::assertSame(['issued' => 1, 'already' => 0, 'failed' => []], $batch);
        $this->ok('POST', '/nursery/services/subscriptions/' . $sub['id'] . '/end', ['ends_on' => '2026-11-30']);
        self::assertSame(422, $this->code('POST', '/nursery/services/subscriptions/' . $sub['id'] . '/invoice', ['on' => '2026-12-05']), 'Hors abonnement.');
        self::assertCount(2, $this->ok('GET', '/nursery/services/subscriptions/' . $sub['id'] . '/invoices'));
    }

    public function testCycleTrimestreEtAnneeSelonLesPeriodesDuSocle(): void
    {
        $term = $this->plan('term');
        $sub = $this->ok('POST', '/nursery/services/subscriptions', ['learner_id' => $this->bao['child'], 'plan_id' => $term['id'], 'starts_on' => '2026-10-15']);
        $invoice = $this->ok('POST', '/nursery/services/subscriptions/' . $sub['id'] . '/invoice', ['on' => '2026-10-21']);
        self::assertSame('T1-2026-09', $invoice['cycle']);
        $year = $this->plan('year');
        $other = $this->ok('POST', '/nursery/services/subscriptions', ['learner_id' => $this->bao['child'], 'plan_id' => $year['id']]);
        self::assertSame(422, $this->code('POST', '/nursery/services/subscriptions/' . $other['id'] . '/invoice', []), 'Aucune période « année » : jamais de cycle inventé.');
    }

    public function testPresenceAuServiceEtPerimetre(): void
    {
        $plan = $this->plan();
        $this->ok('POST', '/nursery/services/subscriptions', ['learner_id' => $this->ada['child'], 'plan_id' => $plan['id'], 'starts_on' => '2026-10-01']);
        $roster = $this->ok('GET', '/nursery/services/attendance?plan_id=' . $plan['id'] . '&date=2026-10-21', null, 'cantine-a');
        self::assertCount(1, $roster['children']);
        self::assertNull($roster['children'][0]['present']);
        $after = $this->ok('POST', '/nursery/services/attendance', ['plan_id' => $plan['id'], 'date' => '2026-10-21', 'marks' => [['learner_id' => $this->ada['child'], 'present' => true]]], 'cantine-a');
        self::assertSame(1, $after['present']);
        self::assertSame(422, $this->code('POST', '/nursery/services/attendance', ['plan_id' => $plan['id'], 'date' => '2026-10-21', 'marks' => [['learner_id' => $this->bao['child'], 'present' => true]]], 'cantine-a'), 'Non abonné.');
        self::assertSame(403, $this->code('POST', '/nursery/services/plans', ['kind' => 'canteen', 'label' => 'X', 'fee_item_id' => 'F', 'billing_cycle' => 'month'], 'cantine-a'));
        self::assertSame(403, $this->code('POST', '/nursery/services/subscriptions', ['learner_id' => $this->ada['child'], 'plan_id' => $plan['id']], 'educatrice-a'));
        $this->grantAll(self::TB, 'directrice-b', self::EB);
        $this->grant(self::TB, 'directrice-b', self::EB, 'nursery.service.manage');
        self::assertSame(404, $this->code('GET', '/nursery/services/attendance?plan_id=' . $plan['id'], null, 'directrice-b', self::TB, self::EB));
        $mine = $this->ok('GET', '/nursery/portal/services?learner_id=' . $this->ada['child'], null, 'parent-' . $this->ada['child']);
        self::assertSame('Cantine (exemple)', $mine[0]['plan_label']);
        self::assertSame(404, $this->code('GET', '/nursery/portal/services?learner_id=' . $this->ada['child'], null, 'parent-' . $this->bao['child']));
    }

    public function testTableauDeBordAgregatsSansNom(): void
    {
        $this->grant(self::TA, 'promoteur-a', self::EA, 'nursery.dashboard.read');
        $this->world->billing->balances[$this->ada['child']] = 25000;
        $this->ok('POST', '/nursery/children/' . $this->ada['child'] . '/arrival', ['arrived_at' => '2026-10-21T07:50:00+01:00'], 'educatrice-a');
        $board = $this->ok('GET', '/nursery/dashboard?date=2026-10-21', null, 'promoteur-a');
        self::assertSame(2, $board['totals']['enrolled']);
        self::assertSame(1, $board['totals']['arrived']);
        self::assertSame(1, $board['totals']['in_arrears']);
        self::assertSame(25000, $board['totals']['balance_xaf']);
        self::assertArrayHasKey('late_pickups', $board);
        $payload = (string) json_encode($board);
        self::assertStringNotContainsString('Ada', $payload, 'Aucune donnée nominative.');
        self::assertSame(403, $this->code('GET', '/nursery/dashboard', null, 'educatrice-a'));
        self::assertSame(422, $this->code('GET', '/nursery/dashboard?date=21-10-2026'));
    }
}
