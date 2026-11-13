<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\PromotionPolicy;
use Tests\Support\Secondary\StrategyHarness;

/**
 * TST-02 (T941), exemple E2 : trimestres 11,30 ; 11,20 ; 12,05 → moyenne annuelle (moyenne des trois moyennes trimestrielles,
 * mode `periods` au 2e niveau d'agrégat) 11,52, proposition « admise » avec le jeu d'exemple. Politique de passage d'exemple
 * vérifiée aux bornes.
 */
final class AichaYearTest extends TestCase
{
    /**
     * @param array<string, mixed> $definition
     * @return array{0: StrategyHarness, 1: list<array<string, mixed>>}
     */
    private static function year(array $definition = [], float $t2 = 11.20, float $t3 = 12.05): array
    {
        $harness = new StrategyHarness($definition);
        $t1 = AichaTrimesterTest::trimester($harness);

        return [$harness, [$t1, StrategyHarness::known('T2', $t2), StrategyHarness::known('T3', $t3)]];
    }

    public function testMoyenneAnnuelleEtAdmise(): void
    {
        $labels = Defaults::DECISION_LABELS;
        $labels[PromotionPolicy::ADMITTED]['label'] = 'Admise';
        [$harness, $trimesters] = self::year(['decision_labels' => $labels]);

        $year = $harness->aggregate('Y', $trimesters);

        self::assertSame(11.52, $year['average'], '(11,30 + 11,20 + 12,05) ÷ 3.');
        self::assertSame(2, $year['result']['extras']['depth']);
        self::assertSame(PromotionPolicy::ADMITTED, $year['result']['extras']['promotion']['code']);
        self::assertSame('Admise', $year['result']['extras']['promotion']['label'], 'Libellé paramétré.');
        self::assertFalse($year['result']['extras']['promotion']['conditional_possible']);

        $decision = $harness->decideYear($trimesters);
        self::assertSame(11.52, $decision['average']);
        self::assertSame('Admise', $decision['decision']);
    }

    public function testPeriodeAPoidsParametre(): void
    {
        $options = Defaults::STRATEGY_OPTIONS;
        $options['period_weights'] = ['T3' => 2];
        [$harness, $trimesters] = self::year(['strategy_options' => $options]);

        self::assertSame(round((11.30 + 11.20 + 2 * 12.05) / 4, 2), $harness->aggregate('Y', $trimesters)['average']);
    }

    /** @return iterable<string, array{float, bool, string}> */
    public static function bornes(): iterable
    {
        yield 'admis à 10' => [10.0, false, PromotionPolicy::ADMITTED];
        yield 'conseil à 9' => [9.0, false, PromotionPolicy::COUNCIL_REVIEW];
        yield 'conseil à 9,99' => [9.99, false, PromotionPolicy::COUNCIL_REVIEW];
        yield 'redoublement sous 9' => [8.99, false, PromotionPolicy::REPEAT];
        yield 'exclusion du redoublant sous 9' => [8.99, true, PromotionPolicy::EXCLUSION];
        yield 'redoublant admis' => [10.5, true, PromotionPolicy::ADMITTED];
    }

    /** @dataProvider bornes */
    public function testPolitiqueDePassageDExemple(float $average, bool $repeater, string $expected): void
    {
        $proposal = PromotionPolicy::propose(Defaults::STRATEGY_OPTIONS, $average, null, ['is_repeater' => $repeater]);

        self::assertSame($expected, $proposal['code'] ?? null);
    }

    public function testSansPolitiqueDePassageAucuneProposition(): void
    {
        $options = Defaults::STRATEGY_OPTIONS;
        unset($options['promotion']);
        [$harness, $trimesters] = self::year(['strategy_options' => $options]);

        self::assertArrayNotHasKey('promotion', $harness->aggregate('Y', $trimesters)['result']['extras']);
        self::assertSame('', $harness->decideYear($trimesters)['decision'], 'Le conseil décide seul.');
    }
}
