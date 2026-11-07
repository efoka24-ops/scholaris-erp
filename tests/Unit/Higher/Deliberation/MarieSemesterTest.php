<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Deliberation;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Tests\Support\Higher\Deliberation\DeliberationFixtures as F;

/**
 * TST-04 et TST-05 (CDC, « Règles de calcul ») : Marie N., L1 S1 Comptabilité et finance, 30 crédits, poids 40/60, arrondi au
 * centième à chaque étape, par la stratégie de production `higher_lmd`.
 */
final class MarieSemesterTest extends TestCase
{
    private HigherLmdStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new HigherLmdStrategy();
    }

    public function testF1NotesDEcEtF2MoyennesDUeArrondiesAChaqueEtape(): void
    {
        $result = $this->strategy->computeLearner(F::rules(), F::marie(), 'normal');

        $notes = [];
        foreach ($result['units'] ?? [] as $unit) {
            foreach ($unit['elements'] as $element) {
                $notes[$element['code']] = $element['note'];
            }
        }
        self::assertSame(['CG' => 10.2, 'TDC' => 12.2, 'MF' => 7.1, 'MIC' => 12.4, 'MAC' => 10.7, 'DC' => 11.8, 'IA' => 14.4, 'ANG' => 14.6, 'BUR' => 15.7], $notes);
        // UE1 = (10,20 × 4 + 12,20 × 2) ÷ 6 = 10,8667 → 10,87.
        self::assertSame(['sub-UE1' => 10.87, 'sub-UE2' => 7.1, 'sub-UE3' => 11.55, 'sub-UE4' => 13.1, 'sub-UE5' => 15.15], $result['subjects']);
        self::assertSame(['C', 'D', 'C+', 'B', 'A'], array_column($result['units'] ?? [], 'letter'));
        self::assertSame([2.0, 1.0, 2.3, 3.0, 3.7], array_column($result['units'] ?? [], 'points'));
    }

    public function testTst04EliminatoireSept(): void
    {
        $result = $this->strategy->computeLearner(F::rules(['eliminatory_unit_mark' => 7]), F::marie(), 'normal');

        self::assertSame(11.55, $result['average'], 'F3 : 346,60 ÷ 30.');
        self::assertSame(2.4, $result['extras']['gpa'] ?? null, 'F5 : (2,00 + 1,00 + 2,30 + 3,00 + 3,70) × 6 ÷ 30.');
        self::assertSame(30, $result['credits_earned']);
        self::assertSame(30, $result['credits_total']);
        self::assertSame(LmdCalculator::COMPENSATED, $result['units']['sub-UE2']['status'] ?? null, 'UE2 acquise par compensation.');
        self::assertSame(LmdCalculator::ACQUIRED, $result['units']['sub-UE1']['status'] ?? null);
        self::assertSame('Passable', $result['mention']);
        self::assertSame('Admis par compensation', $result['deliberation_proposal']);
    }

    public function testEliminatoireSeptEtDemiUe2NonAcquise(): void
    {
        $result = $this->strategy->computeLearner(F::rules(['eliminatory_unit_mark' => 7.5]), F::marie(), 'normal');

        self::assertSame(LmdCalculator::NOT_ACQUIRED, $result['units']['sub-UE2']['status'] ?? null);
        self::assertSame(24, $result['credits_earned']);
    }

    public function testEliminatoireParUe(): void
    {
        $result = $this->strategy->computeLearner(F::rules(['eliminatory_unit_mark' => 7, 'unit_eliminatory' => ['UE2' => 8]]), F::marie(), 'normal');

        self::assertSame(LmdCalculator::NOT_ACQUIRED, $result['units']['sub-UE2']['status'] ?? null);
        self::assertSame(8.0, $result['units']['sub-UE2']['eliminatory'] ?? null);
    }

    public function testTst05EliminatoireHuitPuisRattrapageAOnze(): void
    {
        $normal = $this->strategy->computeLearner(F::rules(['eliminatory_unit_mark' => 8]), F::marie(11.0), 'normal');
        self::assertSame(24, $normal['credits_earned'], 'Session normale : 24 crédits, le rattrapage est ignoré.');
        self::assertSame('Rattrapage', $normal['deliberation_proposal']);
        self::assertNull($normal['mention']);

        $resit = $this->strategy->computeLearner(F::rules(['eliminatory_unit_mark' => 8]), F::marie(11.0), 'resit');
        // Note d'EC = 0,4 × 8 + 0,6 × 11 (CC conservé, le rattrapage remplace l'examen).
        self::assertSame(9.8, $resit['subjects']['sub-UE2']);
        self::assertSame(12.09, $resit['average'], '(346,60 − 42,60 + 58,80) ÷ 30.');
        self::assertSame(LmdCalculator::COMPENSATED, $resit['units']['sub-UE2']['status'] ?? null, 'UE2 compensée après rattrapage.');
        self::assertSame(30, $resit['credits_earned']);
        self::assertSame('Assez bien', $resit['mention']);
        $element = $resit['units']['sub-UE2']['elements'][0];
        self::assertSame([8.0, 6.5, 11.0, 9.8], [$element['cc'], $element['exam'], $element['resit'], $element['note']]);
    }

    public function testPlafondFacultatifApresRattrapage(): void
    {
        $units = F::marie();
        $units[1] = F::ue('UE2', [F::ec('MF', 6, 8, 6.5, 16)]);
        $capped = $this->strategy->computeLearner(F::rules(['eliminatory_unit_mark' => 8, 'resit_unit_cap' => 10]), $units, 'resit');

        self::assertSame(10.0, $capped['subjects']['sub-UE2'], '0,4 × 8 + 0,6 × 16 = 12,80 plafonnée à 10.');
    }

    public function testPoidsCcExamenModifiableParEc(): void
    {
        $result = $this->strategy->computeLearner(F::rules(['element_cc_weights' => ['MF' => 50]]), F::marie(), 'normal');

        self::assertSame(7.25, $result['subjects']['sub-UE2'], '0,5 × 8 + 0,5 × 6,5.');
    }

    public function testGrilleDeLaTutelleRemplaceLaGrilleDExemple(): void
    {
        $grid = [['min' => 10.0, 'letter' => 'P', 'points' => 1.0], ['min' => 0.0, 'letter' => 'E', 'points' => 0.0]];
        $result = $this->strategy->computeLearner(F::rules(['letter_grid' => $grid]), F::marie(), 'normal');

        self::assertSame(['P', 'E', 'P', 'P', 'P'], array_column($result['units'] ?? [], 'letter'));
        self::assertSame([], $this->strategy->optionErrors(['letter_grid' => $grid, 'cc_weight_percent' => 40]));
        self::assertNotSame([], $this->strategy->optionErrors(['letter_grid' => [['min' => 5, 'letter' => 'X', 'points' => 1]]]));
        self::assertNotSame([], $this->strategy->optionErrors(['eliminatory_unit_mark' => 25]));
    }

    public function testPointsDeJuryPlafonnesAUnDemiPointSurLaMoyenneDeSemestre(): void
    {
        $calculator = new LmdCalculator();
        $units = F::marie();
        $units[3] = F::ue('UE4', [F::ec('DC', 3, 4, 4), F::ec('IA', 3, 6, 6)]);
        $base = $calculator->semester(F::rules(), $units, 'normal');
        self::assertSame(9.93, $base['average']);
        self::assertSame(LmdCalculator::NOT_ACQUIRED, $base['units']['sub-UE2']['status'] ?? null);

        $withPoints = $calculator->semester(F::rules(), $units, 'normal', 0.6);
        self::assertSame(0.5, $withPoints['extras']['jury_points'] ?? null, '0,6 demandé → plafonné à 0,5.');
        self::assertSame(10.43, $withPoints['average']);
        self::assertSame(9.93, $withPoints['extras']['raw_average'] ?? null);
        self::assertSame(LmdCalculator::COMPENSATED, $withPoints['units']['sub-UE2']['status'] ?? null);
    }

    public function testCasLimitesAMoinsDUnDemiPoint(): void
    {
        $calculator = new LmdCalculator();
        $rules = F::rules(['eliminatory_unit_mark' => 7.5]);
        $result = $calculator->semester($rules, F::marie(), 'normal');
        $cases = $calculator->borderline($rules, $result);

        self::assertContains(['kind' => 'eliminatory', 'unit' => 'UE2', 'value' => 7.1, 'threshold' => 7.5, 'gap' => 0.4], $cases);
        self::assertContains(['kind' => 'mention', 'unit' => null, 'value' => 11.55, 'threshold' => 12.0, 'gap' => 0.45], $cases);
    }
}
