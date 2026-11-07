<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Deliberation;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherBtsHndStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\Marks;
use Tests\Support\Higher\Deliberation\DeliberationFixtures as F;

/** Règles BTS/HND (formation interne) : 0,4 devoirs + 0,6 examen, coefficients, moyenne annuelle, passage ≥ 10, rang avec ex æquo. */
final class BtsHndTest extends TestCase
{
    public function testNoteDeMatiereEtMoyenneSemestriellePondereeParCoefficients(): void
    {
        $units = [
            F::ue('COMPTA', [F::ec('COMPTA', 0, 12, 9)], 0, 4.0),
            F::ue('ECO', [F::ec('ECO', 0, 14, 11)], 0, 2.0),
        ];
        $result = (new HigherBtsHndStrategy())->computeLearner(F::rules([], 'higher_bts_hnd'), $units, 'normal');

        self::assertSame(['sub-COMPTA' => 10.2, 'sub-ECO' => 12.2], $result['subjects']);
        self::assertSame(10.87, $result['average'], '(10,20 × 4 + 12,20 × 2) ÷ 6.');
        self::assertSame('Admis', $result['deliberation_proposal']);
    }

    public function testPassageSiMoyenneAnnuelleAuMoinsDix(): void
    {
        $strategy = new HigherBtsHndStrategy();
        $period = static fn (string $id, float $average): array => ['period_id' => $id, 'average' => $average, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []];

        $pass = $strategy->decideYear(F::rules([], 'higher_bts_hnd'), [$period('S1', 9.5), $period('S2', 10.5)], []);
        self::assertSame(['Admis', 10.0], [$pass['decision'], $pass['average']]);
        $fail = $strategy->decideYear(F::rules([], 'higher_bts_hnd'), [$period('S1', 9.5), $period('S2', 10.4)], []);
        self::assertSame(['Redouble', 9.95], [$fail['decision'], $fail['average']]);
    }

    public function testRangDansLeGroupeAvecExAequo(): void
    {
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 2, 'd' => 4, 'e' => null], Marks::ranks(['a' => 15.0, 'b' => 12.5, 'c' => 12.5, 'd' => 9.0, 'e' => null]));
    }
}
