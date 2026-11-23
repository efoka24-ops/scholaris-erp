<?php

declare(strict_types=1);

namespace Tests\Support\Secondary;

/**
 * Calcul de référence de NF-S5 (T947), écrit SANS la stratégie ni le socle : arithmétique ENTIÈRE exacte (notes en quarts de
 * point, moyennes en centièmes, arrondi au centième, demi vers le haut, à chaque étape), règles du jeu `example_cdc` :
 *  - note de séquence d'une matière = Σ (note × poids) ÷ Σ poids des épreuves présentes (absence excusée exclue) ;
 *  - moyenne de trimestre d'une matière = moyenne de ses notes de séquence ; moyenne annuelle d'une matière = moyenne de ses
 *    moyennes de trimestre ; matière sans note = non évaluée, exclue avec son coefficient ;
 *  - moyenne générale d'une séquence ou d'un trimestre = Σ (moyenne × coefficient) ÷ Σ coefficients des matières évaluées ;
 *  - moyenne générale annuelle = moyenne des moyennes générales des trimestres ;
 *  - rang : ordre décroissant, ex æquo au même rang, suivant décalé.
 *
 * Classe : subjects (id => coefficient entier), trimesters (id => liste de séquences), grades (élève => séquence => matière =>
 * liste de [quarts|null, poids entier]).
 *
 * @phpstan-type ClassData array{subjects: array<string, int>, trimesters: array<string, list<string>>, grades: array<string, array<string, array<string, list<array{0: int|null, 1: int}>>>>}
 */
final class ReferenceCalculator
{
    /**
     * Résultats en centièmes (entiers) : période => élève => {general, subjects, rank}.
     *
     * @param ClassData $class
     * @return array<string, array<string, array{general: int|null, subjects: array<string, int|null>, rank: int|null}>>
     */
    public static function compute(array $class): array
    {
        $out = [];
        $trimesterSubjects = [];
        foreach ($class['trimesters'] as $trimester => $sequences) {
            foreach ($sequences as $sequence) {
                foreach ($class['grades'] as $person => $bySequence) {
                    $subjects = [];
                    foreach ($class['subjects'] as $subject => $coefficient) {
                        $subjects[$subject] = self::sequenceSubject($bySequence[$sequence][$subject] ?? []);
                    }
                    $out[$sequence][$person] = ['general' => self::weighted($subjects, $class['subjects']), 'subjects' => $subjects, 'rank' => null];
                }
            }
            foreach (array_keys($class['grades']) as $person) {
                $subjects = [];
                foreach (array_keys($class['subjects']) as $subject) {
                    $values = [];
                    foreach ($sequences as $sequence) {
                        $values[] = $out[$sequence][$person]['subjects'][$subject];
                    }
                    $subjects[$subject] = self::mean($values);
                }
                $trimesterSubjects[$person][$trimester] = $subjects;
                $out[$trimester][$person] = ['general' => self::weighted($subjects, $class['subjects']), 'subjects' => $subjects, 'rank' => null];
            }
        }
        foreach (array_keys($class['grades']) as $person) {
            $subjects = [];
            foreach (array_keys($class['subjects']) as $subject) {
                $subjects[$subject] = self::mean(array_map(static fn (array $s): ?int => $s[$subject], array_values($trimesterSubjects[$person])));
            }
            $generals = [];
            foreach (array_keys($class['trimesters']) as $trimester) {
                $generals[] = $out[$trimester][$person]['general'];
            }
            $out['year'][$person] = ['general' => self::mean($generals), 'subjects' => $subjects, 'rank' => null];
        }
        foreach ($out as $period => $people) {
            $out[$period] = self::ranked($people);
        }

        return $out;
    }

    /** @param list<array{0: int|null, 1: int}> $entries */
    private static function sequenceSubject(array $entries): ?int
    {
        $numerator = 0;
        $weights = 0;
        foreach ($entries as [$quarters, $weight]) {
            if ($quarters === null) {
                continue;
            }
            $numerator += $quarters * $weight;
            $weights += $weight;
        }

        // (Σ quarts × poids) ÷ (4 × Σ poids) points, exprimés en centièmes.
        return $weights === 0 ? null : self::divide($numerator * 100, 4 * $weights);
    }

    /**
     * @param array<string, int|null> $subjects centièmes
     * @param array<string, int> $coefficients
     */
    private static function weighted(array $subjects, array $coefficients): ?int
    {
        $sum = 0;
        $weights = 0;
        foreach ($subjects as $subject => $value) {
            if ($value !== null) {
                $sum += $value * $coefficients[$subject];
                $weights += $coefficients[$subject];
            }
        }

        return $weights === 0 ? null : self::divide($sum, $weights);
    }

    /** @param list<int|null> $values centièmes */
    private static function mean(array $values): ?int
    {
        $present = array_values(array_filter($values, static fn (?int $v): bool => $v !== null));

        return $present === [] ? null : self::divide(array_sum($present), count($present));
    }

    /** Quotient exact arrondi à l'entier, demi vers le haut (valeurs positives). */
    private static function divide(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * @param array<string, array{general: int|null, subjects: array<string, int|null>, rank: int|null}> $people
     * @return array<string, array{general: int|null, subjects: array<string, int|null>, rank: int|null}>
     */
    private static function ranked(array $people): array
    {
        foreach ($people as $person => $row) {
            if ($row['general'] === null) {
                continue;
            }
            $better = 0;
            foreach ($people as $other) {
                if ($other['general'] !== null && $other['general'] > $row['general']) {
                    ++$better;
                }
            }
            $people[$person]['rank'] = $better + 1;
        }

        return $people;
    }
}
