<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Tests\Support\Secondary\ReferenceCalculator;
use Tests\Support\Secondary\StrategyHarness;

/**
 * NF-S5 (T947, SC-3) : 100 classes générées (graine fixe, données fictives : 20 à 45 élèves, 6 à 11 matières de coefficient 1
 * à 6, six séquences en trois trimestres, une à trois épreuves pondérées par séquence, notes au quart de point, quelques
 * absences excusées et matières non évaluées). Moyennes de matière et générales des séquences, trimestres et de l'année, et
 * rangs, comparés au calcul de référence entier écrit séparément (tests/Support/Secondary/ReferenceCalculator) : écart 0,00.
 */
final class ReferenceCalculationTest extends TestCase
{
    private const SEED = 20261029;
    private const CLASSES = 100;
    private const TRIMESTERS = ['T1' => ['S1', 'S2'], 'T2' => ['S3', 'S4'], 'T3' => ['S5', 'S6']];

    /** @return array{subjects: array<string, int>, trimesters: array<string, list<string>>, grades: array<string, array<string, array<string, list<array{0: int|null, 1: int}>>>>} */
    private static function generate(): array
    {
        $subjects = [];
        foreach (range(1, mt_rand(6, 11)) as $index) {
            $subjects['m' . $index] = mt_rand(1, 6);
        }
        $grades = [];
        foreach (range(1, mt_rand(20, 45)) as $student) {
            foreach (self::TRIMESTERS as $sequences) {
                foreach ($sequences as $sequence) {
                    foreach (array_keys($subjects) as $subject) {
                        $entries = [];
                        if (mt_rand(1, 100) > 3) {
                            foreach (range(1, mt_rand(1, 3)) as $ignored) {
                                $entries[] = [mt_rand(1, 100) <= 4 ? null : mt_rand(0, 80), mt_rand(1, 3)];
                            }
                        }
                        $grades['p' . $student][$sequence][$subject] = $entries;
                    }
                }
            }
        }

        return ['subjects' => $subjects, 'trimesters' => self::TRIMESTERS, 'grades' => $grades];
    }

    /**
     * Même classe calculée par la stratégie (séquences, trimestres, année) et rangée par le socle.
     *
     * @param array{subjects: array<string, int>, trimesters: array<string, list<string>>, grades: array<string, array<string, array<string, list<array{0: int|null, 1: int}>>>>} $class
     * @return array<string, array<string, array{general: float|null, subjects: array<string, float|null>, rank: int|null}>>
     */
    private static function strategy(StrategyHarness $harness, array $class): array
    {
        $summaries = [];
        foreach ($class['grades'] as $person => $bySequence) {
            $trimesters = [];
            foreach ($class['trimesters'] as $trimester => $sequences) {
                $children = [];
                foreach ($sequences as $sequence) {
                    $units = [];
                    foreach ($class['subjects'] as $subject => $coefficient) {
                        $notes = array_map(static fn (array $e): array => [$e[0] === null ? null : $e[0] / 4, $e[1]], $bySequence[$sequence][$subject]);
                        $units[] = StrategyHarness::unit($subject, (float) $coefficient, $notes);
                    }
                    $children[] = $summaries[$sequence][$person] = $harness->sequence($sequence, $units);
                }
                $trimesters[] = $summaries[$trimester][$person] = $harness->aggregate($trimester, $children);
            }
            $summaries['year'][$person] = $harness->aggregate('Y', $trimesters);
        }
        $engine = new RuleEngine();
        $out = [];
        foreach ($summaries as $period => $people) {
            $ranks = $engine->ranks(array_map(static fn (array $s): ?float => $s['average'], $people));
            foreach ($people as $person => $summary) {
                $subjects = [];
                foreach (array_keys($class['subjects']) as $subject) {
                    $value = $summary['units'][$subject]['average'] ?? null;
                    $subjects[$subject] = $value === null ? null : (float) $value;
                }
                $out[$period][$person] = ['general' => $summary['average'], 'subjects' => $subjects, 'rank' => $ranks[$person]];
            }
        }

        return $out;
    }

    private static function hundredths(?float $value): ?int
    {
        return $value === null ? null : (int) round($value * 100);
    }

    public function testCentClassesSansAucunEcartAvecLeCalculDeReference(): void
    {
        mt_srand(self::SEED);
        $harness = new StrategyHarness();
        $compared = 0;
        $notEvaluated = 0;
        for ($index = 0; $index < self::CLASSES; ++$index) {
            $class = self::generate();
            $reference = ReferenceCalculator::compute($class);
            $computed = self::strategy($harness, $class);
            self::assertSame(array_keys($reference), array_keys($computed));
            foreach ($reference as $period => $people) {
                foreach ($people as $person => $expected) {
                    $actual = $computed[$period][$person];
                    $where = 'classe ' . $index . ', ' . $period . ', ' . $person;
                    self::assertSame($expected['general'], self::hundredths($actual['general']), $where . ' : moyenne générale');
                    self::assertSame($expected['rank'], $actual['rank'], $where . ' : rang');
                    foreach ($expected['subjects'] as $subject => $value) {
                        self::assertSame($value, self::hundredths($actual['subjects'][$subject]), $where . ', ' . $subject);
                        $value === null && ++$notEvaluated;
                        ++$compared;
                    }
                }
            }
        }
        self::assertGreaterThan(100000, $compared, 'Volume comparé.');
        self::assertGreaterThan(0, $notEvaluated, 'Des matières non évaluées sont couvertes.');
    }
}
