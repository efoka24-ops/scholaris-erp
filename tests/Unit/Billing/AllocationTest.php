<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Billing\Domain\AllocationPlanner;
use Scholaris\Modules\Socle\Billing\Domain\AllocationRules;
use Scholaris\Modules\Socle\Billing\Domain\Due;
use Scholaris\Modules\Socle\Billing\Domain\OldestFirstRule;
use Scholaris\Modules\Socle\Billing\Domain\RegistrationOverdueUpcomingRule;

final class AllocationTest extends TestCase
{
    private function due(string $id, string $kind, string $dueOn, int $outstanding, string $learner = 'l1'): Due
    {
        return new Due($id, 'inv-' . $learner, $learner, 'e1', $kind, $dueOn, $outstanding);
    }

    /** @return list<Due> */
    private function dues(): array
    {
        return [
            $this->due('t3', 'tuition', '2027-04-15', 120000),
            $this->due('t1', 'tuition', '2026-10-15', 120000),
            $this->due('ins', 'registration', '2026-10-08', 50000),
            $this->due('t2', 'tuition', '2027-01-15', 120000),
        ];
    }

    public function testInscriptionPuisRetardsPuisAVenir(): void
    {
        $plan = (new AllocationPlanner())->plan(170000, $this->dues(), new RegistrationOverdueUpcomingRule(), '2026-10-10');

        self::assertSame(['ins' => 50000, 't1' => 120000], $plan->byInstallment());
        self::assertSame(0, $plan->surplus);
        self::assertSame('registration_overdue_upcoming@1', $plan->ruleVersion);
    }

    public function testLesRetardsPassentAvantLesTranchesAVenirMemeSiLaDateLimiteEstPlusLointaine(): void
    {
        // Au 20 janvier, la tranche 2 est en retard et la tranche 3 est à venir ; la tranche 1 est déjà soldée.
        $dues = [$this->due('t2', 'tuition', '2027-01-15', 120000), $this->due('t3', 'tuition', '2027-04-15', 120000)];
        $plan = (new AllocationPlanner())->plan(100000, $dues, new RegistrationOverdueUpcomingRule(), '2027-01-20');

        self::assertSame(['t2' => 100000], $plan->byInstallment());

        $plan = (new AllocationPlanner())->plan(140000, [$this->due('t2', 'tuition', '2027-01-15', 20000), $this->due('t3', 'tuition', '2027-04-15', 120000)], new RegistrationOverdueUpcomingRule(), '2027-02-02');
        self::assertSame(['t2' => 20000, 't3' => 120000], $plan->byInstallment());
    }

    public function testUneTrancheEstEnRetardLeLendemainDeSaDateLimite(): void
    {
        $due = $this->due('t1', 'tuition', '2026-10-15', 1000);

        self::assertFalse($due->isOverdue('2026-10-15'));
        self::assertTrue($due->isOverdue('2026-10-16'));
    }

    public function testLesRetardsSontCouvertsDeLaPlusAncienneALaPlusRecente(): void
    {
        $dues = [$this->due('b', 'tuition', '2026-12-15', 100), $this->due('a', 'tuition', '2026-11-15', 100), $this->due('c', 'tuition', '2027-01-15', 100)];
        $plan = (new AllocationPlanner())->plan(150, $dues, new RegistrationOverdueUpcomingRule(), '2027-02-01');

        self::assertSame(['a' => 100, 'b' => 50], $plan->byInstallment());
    }

    public function testLeSurplusResteEnCredit(): void
    {
        $plan = (new AllocationPlanner())->plan(300, [$this->due('a', 'tuition', '2026-11-15', 100)], new OldestFirstRule(), '2026-11-01');

        self::assertSame(['a' => 100], $plan->byInstallment());
        self::assertSame(200, $plan->surplus);
    }

    public function testLaRegleParametrableChangeLOrdre(): void
    {
        $dues = [$this->due('ins', 'registration', '2026-10-30', 50), $this->due('old', 'tuition', '2026-10-15', 50)];

        $default = (new AllocationPlanner())->plan(50, $dues, AllocationRules::byName(''), '2026-11-15');
        $oldest = (new AllocationPlanner())->plan(50, $dues, AllocationRules::byName('oldest_first'), '2026-11-15');

        self::assertSame(['ins' => 50], $default->byInstallment());
        self::assertSame(['old' => 50], $oldest->byInstallment());
        self::assertSame('oldest_first@1', $oldest->ruleVersion);
    }

    public function testPaiementMultiEnfantsReparti(): void
    {
        $dues = [$this->due('y1', 'tuition', '2026-10-15', 35000, 'yann'), $this->due('n1', 'tuition', '2026-10-15', 0, 'nadia')];
        $plan = (new AllocationPlanner())->plan(35000, $dues, new RegistrationOverdueUpcomingRule(), '2026-10-20');

        self::assertSame(['y1' => 35000], $plan->byInstallment());
    }

    public function testMontantNegatifRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AllocationPlanner())->plan(-1, [], new RegistrationOverdueUpcomingRule(), '2026-10-20');
    }
}
