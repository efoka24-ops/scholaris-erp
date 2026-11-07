<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Tests\Support\Secondary\StrategyHarness;

/**
 * TST-01 (T940), exemple E1 du CDC « Aïcha M. », 4e, 1er trimestre, jeu `example_cdc` (coefficients et seuils fournis par le
 * test, non officiels) : 11 matières, Σ coefficients 22, total 248,50, moyenne 11,30, aucune proposition du travail, la matière
 * à 7,75 signalée.
 */
final class AichaTrimesterTest extends TestCase
{
    /** matière => [coefficient, séquence 1, séquence 2, moyenne attendue] */
    public const SUBJECTS = [
        'fr' => [4, 12, 10.5, 11.25], 'en' => [3, 9, 11, 10.00], 'math' => [4, 8.5, 7, 7.75], 'pct' => [2, 11, 13, 12.00],
        'svt' => [2, 14, 12.5, 13.25], 'hist' => [2, 10, 12, 11.00], 'geo' => [1, 13, 11, 12.00], 'ecm' => [1, 15, 14, 14.50],
        'lcn' => [1, 16, 15, 15.50], 'info' => [1, 12, 14, 13.00], 'eps' => [1, 14, 16, 15.00],
    ];

    /**
     * @param array<string, array{0: int, 1: float|int, 2: float|int, 3: float}> $subjects
     * @param list<string> $practical matières de type « practical »
     * @return array<string, mixed> résumé du trimestre
     */
    public static function trimester(StrategyHarness $harness, array $subjects = self::SUBJECTS, string $kind = 'subject', array $practical = []): array
    {
        $sequences = [];
        foreach ([1, 2] as $index) {
            $units = [];
            foreach ($subjects as $id => $row) {
                $units[] = StrategyHarness::unit($id, (float) $row[0], [$row[$index]], in_array($id, $practical, true) ? 'practical' : $kind);
            }
            $sequences[] = $harness->sequence('S' . $index, $units);
        }

        return $harness->aggregate('T1', $sequences);
    }

    public function testMoyennesDuTrimestreTotalEtCoefficients(): void
    {
        $t1 = self::trimester(new StrategyHarness());

        $total = 0.0;
        $coefficients = 0.0;
        foreach (self::SUBJECTS as $id => [$coefficient, , , $expected]) {
            $unit = $t1['units'][$id];
            self::assertEqualsWithDelta($expected, $unit['average'], 1e-9, $id);
            self::assertSame(1, $unit['depth']);
            $total += $unit['average'] * $unit['coefficient'];
            $coefficients += $unit['coefficient'];
        }
        self::assertEqualsWithDelta(248.50, $total, 1e-9);
        self::assertEqualsWithDelta(22.0, $coefficients, 1e-9);
        self::assertSame(11.30, $t1['average']);
    }

    public function testAucunePropositionEtMatiereSousLaBarreSignalee(): void
    {
        $extras = self::trimester(new StrategyHarness())['result']['extras'];

        self::assertNull($extras['work_proposal'], '11,30 : ni distinction ni sanction avec le jeu d\'exemple.');
        self::assertSame(['math'], $extras['subject_alerts']);
        self::assertSame([], $extras['not_evaluated']);
        self::assertSame([], $extras['practical_alerts']);
        self::assertArrayNotHasKey('promotion', $extras, 'Pas de proposition de passage au niveau du trimestre.');
    }

    public function testLesValeursViennentDesReglesPasDuCode(): void
    {
        $options = Defaults::STRATEGY_OPTIONS;
        $options['subject_alert_below'] = 8.0;
        $options['work_proposals'] = [['code' => 'honour_roll', 'label' => 'Tableau d\'honneur', 'kind' => 'distinction', 'min' => 11.0]];
        $harness = new StrategyHarness(['strategy_options' => $options]);

        $t1 = self::trimester($harness);

        self::assertSame(['math'], $t1['result']['extras']['subject_alerts'], '7,75 < 8.');
        self::assertSame('honour_roll', $t1['result']['extras']['work_proposal']['code'], 'Seuil de l\'établissement, pas du CDC.');

        $noOptions = new StrategyHarness(['strategy_options' => []]);
        $bare = self::trimester($noOptions);
        self::assertSame(11.30, $bare['average'], 'Le calcul ne dépend d\'aucun seuil.');
        self::assertNull($bare['result']['extras']['work_proposal']);
        self::assertSame([], $bare['result']['extras']['subject_alerts']);
    }

    public function testMatiereNonEvalueeExclueAvecSonCoefficientEtSignalee(): void
    {
        $harness = new StrategyHarness();
        $subjects = self::SUBJECTS;
        $sequences = [];
        foreach ([1, 2] as $index) {
            $units = [];
            foreach ($subjects as $id => $row) {
                $units[] = StrategyHarness::unit($id, (float) $row[0], $id === 'eps' ? [] : [$row[$index]]);
            }
            $sequences[] = $harness->sequence('S' . $index, $units);
        }
        $t1 = $harness->aggregate('T1', $sequences);

        self::assertSame(['eps'], $t1['result']['extras']['not_evaluated']);
        self::assertNull($t1['units']['eps']['average']);
        self::assertFalse($t1['units']['eps']['evaluated']);
        self::assertSame(round(233.5 / 21, 2), $t1['average'], 'Σ 248,50 − 15 sur Σ 22 − 1.');
    }

    public function testArrondiAChaqueEtapeOuALaFin(): void
    {
        $options = Defaults::STRATEGY_OPTIONS;
        $harness = new StrategyHarness(['strategy_options' => $options]);
        // Trois épreuves de séquence : 10, 10, 11 → 10,333… arrondi à 10,33 à chaque étape.
        $s1 = $harness->sequence('S1', [StrategyHarness::unit('a', 1.0, [10, 10, 11])]);
        $s2 = $harness->sequence('S2', [StrategyHarness::unit('a', 1.0, [10, 10, 11])]);
        self::assertSame(10.33, $harness->aggregate('T1', [$s1, $s2])['average']);

        $options['round_each_step'] = false;
        $raw = new StrategyHarness(['strategy_options' => $options]);
        $r1 = $raw->sequence('S1', [StrategyHarness::unit('a', 1.0, [10, 10, 11]), StrategyHarness::unit('b', 2.0, [12.125])]);
        $r2 = $raw->sequence('S2', [StrategyHarness::unit('a', 1.0, [10, 10, 11]), StrategyHarness::unit('b', 2.0, [12.125])]);
        self::assertEqualsWithDelta(12.13, $r1['units']['b']['average'], 1e-9, 'Affichage arrondi.');
        self::assertSame(round((31 / 3 + 2 * 12.125) / 3, 2), $raw->aggregate('T1', [$r1, $r2])['average'], 'Valeurs brutes transmises.');
    }

    public function testOptionsRefuseesALaPublication(): void
    {
        $strategy = (new StrategyHarness())->strategy;

        self::assertSame([], $strategy->optionErrors(Defaults::STRATEGY_OPTIONS));
        self::assertNotSame([], $strategy->optionErrors(['unknown' => 1]));
        self::assertNotSame([], $strategy->optionErrors(['aggregation_modes' => ['average']]));
        self::assertNotSame([], $strategy->optionErrors(['work_proposals' => [['code' => 'x', 'min' => 1, 'below' => 2]]]));
        self::assertNotSame([], $strategy->optionErrors(['promotion' => ['admit_from' => 9, 'review_from' => 10]]));
        self::assertNotSame([], $strategy->optionErrors(['practical' => ['min' => 'huit']]));
        self::assertNotSame([], $strategy->optionErrors(['period_weights' => ['S1' => -1]]));
        self::assertNotSame([], $strategy->optionErrors(['round_each_step' => 'oui']));
    }
}
