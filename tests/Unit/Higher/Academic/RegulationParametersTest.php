<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Academic;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Academic\Domain\Regime;
use Scholaris\Modules\Apps\Higher\Academic\Domain\RegulationParameters;

/** EX-ACA-05 : valeurs par défaut du CDC (« Règles de calcul ») et contrôle des surcharges. */
final class RegulationParametersTest extends TestCase
{
    public function testDefautsLmdEtBts(): void
    {
        $lmd = RegulationParameters::defaults(Regime::Lmd);
        self::assertSame([40, 2, 0.25, 10, 7, 60, 45, 30, 0.5, 2, null], [
            $lmd['cc_weight_percent'], $lmd['rounding_decimals'], $lmd['grade_step'], $lmd['unit_pass_mark'], $lmd['eliminatory_unit_mark'],
            $lmd['progression_admitted_credits'], $lmd['progression_debts_min_credits'], $lmd['credits_per_semester'], $lmd['jury_points_max'],
            $lmd['max_consecutive_enrolments'], $lmd['resit_unit_cap'],
        ]);
        self::assertArrayNotHasKey('annual_pass_average', $lmd);

        $hnd = RegulationParameters::defaults(Regime::Hnd);
        self::assertSame([10, 70], [$hnd['annual_pass_average'], $hnd['national_exam_min_attendance_percent']]);
        self::assertArrayNotHasKey('credits_per_semester', $hnd);
    }

    public function testSurchargesControlees(): void
    {
        [$values, $errors] = RegulationParameters::merge(RegulationParameters::defaults(Regime::Lmd), ['eliminatory_unit_mark' => 8, 'resit_unit_cap' => 10, 'compensation_enabled' => false]);
        self::assertSame([], $errors);
        self::assertSame([8.0, 10.0, false], [$values['eliminatory_unit_mark'], $values['resit_unit_cap'], $values['compensation_enabled']]);

        [, $errors] = RegulationParameters::merge([], [
            'cc_weight_percent' => 40.5, 'compensation_enabled' => 'oui', 'grade_step' => 2, 'inconnu' => 1,
            'progression_admitted_credits' => 40, 'progression_debts_min_credits' => 45,
        ]);
        self::assertSame([
            'parameters.cc_weight_percent', 'parameters.compensation_enabled', 'parameters.grade_step', 'parameters.inconnu',
            'parameters.progression_debts_min_credits',
        ], array_keys($errors));
    }
}
