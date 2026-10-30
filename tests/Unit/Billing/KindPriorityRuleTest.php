<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Billing\Domain\AllocationPlanner;
use Scholaris\Modules\Socle\Billing\Domain\AllocationRules;
use Scholaris\Modules\Socle\Billing\Domain\Due;
use Scholaris\Modules\Socle\Billing\Domain\KindPriorityRule;
use Scholaris\Modules\Socle\Billing\Domain\RegistrationOverdueUpcomingRule;

/** Règle d'affectation « par nature » (SS7, RG-FIN-4, TST-07). Données fictives. */
final class KindPriorityRuleTest extends TestCase
{
    private function due(string $id, string $learner, string $kind, string $dueOn, int $outstanding, int $sequence = 0, ?string $invoice = null): Due
    {
        return new Due($id, $invoice ?? 'inv-' . $learner, $learner, 'e1', $kind, $dueOn, $outstanding, $sequence);
    }

    /**
     * Famille fictive de l'exemple du cahier des charges : deux enfants, inscription 3 500 et APEE 10 000 chacun,
     * tranche 1 de 57 000 chacun au 30 septembre, tranche 2 plus tard, cantine (service annexe).
     *
     * @return list<Due>
     */
    private function family(): array
    {
        return [
            $this->due('b-t2', 'kid-b', 'tuition', '2026-12-15', 40000, 2, 'inv-02'),
            $this->due('a-can', 'kid-a', 'canteen', '2026-09-15', 15000, 0, 'inv-01'),
            $this->due('a-t1', 'kid-a', 'tuition', '2026-09-30', 57000, 1, 'inv-01'),
            $this->due('b-apee', 'kid-b', 'apee', '2026-10-31', 10000, 0, 'inv-02'),
            $this->due('b-t1', 'kid-b', 'tuition', '2026-09-30', 57000, 1, 'inv-02'),
            $this->due('a-ins', 'kid-a', 'registration', '2026-09-01', 3500, 0, 'inv-01'),
            $this->due('a-apee', 'kid-a', 'apee', '2026-10-31', 10000, 0, 'inv-01'),
            $this->due('b-ins', 'kid-b', 'registration', '2026-09-01', 3500, 0, 'inv-02'),
            $this->due('a-t2', 'kid-a', 'tuition', '2026-12-15', 40000, 2, 'inv-01'),
        ];
    }

    /**
     * @param list<Due> $dues
     * @return list<string>
     */
    private static function ids(array $dues): array
    {
        return array_map(static fn (Due $d): string => $d->installmentId, $dues);
    }

    public function testOrdreInscriptionApeePensionPuisServicesTrancheParTranche(): void
    {
        $ordered = (new KindPriorityRule())->order($this->family(), '2026-09-25');

        self::assertSame(['a-ins', 'b-ins', 'a-apee', 'b-apee', 'a-t1', 'b-t1', 'a-t2', 'b-t2', 'a-can'], self::ids($ordered));
    }

    public function testExempleDuCahierDesCharges120000(): void
    {
        $plan = (new AllocationPlanner())->plan(120000, $this->family(), AllocationRules::byName('kind_priority'), '2026-09-25');
        $byInstallment = $plan->byInstallment();

        self::assertSame(7000, $byInstallment['a-ins'] + $byInstallment['b-ins'], 'Inscriptions soldées.');
        self::assertSame(20000, $byInstallment['a-apee'] + $byInstallment['b-apee'], 'APEE soldée.');
        self::assertSame(93000, $byInstallment['a-t1'] + $byInstallment['b-t1'], '93 000 sur les tranches 1.');
        self::assertSame(21000, 57000 * 2 - ($byInstallment['a-t1'] + $byInstallment['b-t1']), 'Reste dû au 30 septembre.');
        self::assertArrayNotHasKey('a-t2', $byInstallment);
        self::assertSame(0, $plan->surplus);
        self::assertSame('kind_priority@1', $plan->ruleVersion);
    }

    public function testOrdreDesNaturesParametreEtJokerAuMilieu(): void
    {
        $rule = AllocationRules::byName('kind_priority', ['tuition', '*', 'registration']);
        $ordered = $rule->order($this->family(), '2026-09-25');

        self::assertSame(['a-t1', 'b-t1', 'a-t2', 'b-t2', 'a-can', 'a-apee', 'b-apee', 'a-ins', 'b-ins'], self::ids($ordered));
    }

    public function testSansJokerLesNaturesNonListeesPassentEnDernier(): void
    {
        $ordered = (new KindPriorityRule(['apee']))->order($this->family(), '2026-09-25');

        self::assertSame(['a-apee', 'b-apee'], array_slice(self::ids($ordered), 0, 2));
        self::assertSame(['a-ins', 'b-ins', 'a-can', 'a-t1', 'b-t1', 'a-t2', 'b-t2'], array_slice(self::ids($ordered), 2), 'Puis échéance, tranche, enfant.');
    }

    public function testEnrolledFirstSuitLaPremiereFactureEtLesRangsFournisLaRemplacent(): void
    {
        $dues = [
            $this->due('a-t1', 'kid-a', 'tuition', '2026-09-30', 57000, 1, 'inv-09'),
            $this->due('b-t1', 'kid-b', 'tuition', '2026-09-30', 57000, 1, 'inv-03'),
        ];

        self::assertSame(['b-t1', 'a-t1'], self::ids((new KindPriorityRule())->order($dues, '2026-09-25')), 'Premier facturé d\'abord.');
        self::assertSame(['a-t1', 'b-t1'], self::ids((new KindPriorityRule())->withSiblingRanks(['kid-a' => 0, 'kid-b' => 1])->order($dues, '2026-09-25')));
    }

    public function testListeVideOuDoublonsRetombentSurLOrdreParDefaut(): void
    {
        self::assertSame(KindPriorityRule::DEFAULT_KIND_ORDER, (new KindPriorityRule([]))->kindOrder());
        self::assertSame(['tuition', 'apee'], (new KindPriorityRule(['tuition', 'tuition', 'apee', '']))->kindOrder());
    }

    public function testLeDefautResteRegistrationOverdueUpcoming(): void
    {
        self::assertInstanceOf(RegistrationOverdueUpcomingRule::class, AllocationRules::byName(''));
        self::assertInstanceOf(RegistrationOverdueUpcomingRule::class, AllocationRules::byName('inconnue', ['tuition']));
        self::assertInstanceOf(KindPriorityRule::class, AllocationRules::byName('kind_priority'));
    }
}
