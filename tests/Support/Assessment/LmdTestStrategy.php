<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Stratégie de test de type LMD, écrite comme une application l'écrirait (Contracts seulement). La stratégie de
 * production sera écrite dans Apps/Higher. Règles du CDC (valeurs par défaut, toutes en options) :
 *  F1 note d'EC = cc_weight × CC + exam_weight × examen (le rattrapage remplace l'examen, le CC est conservé) ;
 *  F2 moyenne d'UE = Σ(note EC × crédits EC) ÷ Σ crédits ; F3 moyenne de semestre = Σ(note EC × crédits EC) ÷ Σ crédits ;
 *  arrondi au centième à chaque étape ; UE acquise si ≥ unit_pass, compensée si ≥ eliminatory et semestre ≥ semester_pass ;
 *  lettres et points par UE, F5 MGP ; décisions annuelles 60 / 45-59 / < 45, exclusion après max_enrolments inscriptions.
 *
 * @phpstan-import-type Unit from RuleStrategy
 * @phpstan-import-type Entry from RuleStrategy
 */
final class LmdTestStrategy implements RuleStrategy
{
    public const LETTERS = [
        ['min' => 16.0, 'letter' => 'A+', 'points' => 4.0], ['min' => 15.0, 'letter' => 'A', 'points' => 3.7],
        ['min' => 14.0, 'letter' => 'B+', 'points' => 3.3], ['min' => 13.0, 'letter' => 'B', 'points' => 3.0],
        ['min' => 12.0, 'letter' => 'B-', 'points' => 2.7], ['min' => 11.0, 'letter' => 'C+', 'points' => 2.3],
        ['min' => 10.0, 'letter' => 'C', 'points' => 2.0], ['min' => 9.0, 'letter' => 'C-', 'points' => 1.7],
        ['min' => 8.0, 'letter' => 'D+', 'points' => 1.3], ['min' => 7.0, 'letter' => 'D', 'points' => 1.0],
        ['min' => 0.0, 'letter' => 'F', 'points' => 0.0],
    ];

    public function key(): string
    {
        return 'lmd_test';
    }

    public function optionErrors(array $options): array
    {
        $errors = [];
        foreach (['cc_weight', 'exam_weight', 'eliminatory', 'unit_pass', 'semester_pass'] as $key) {
            if (isset($options[$key]) && !is_int($options[$key]) && !is_float($options[$key])) {
                $errors[] = $key . ' doit être un nombre.';
            }
        }

        return $errors;
    }

    public function computeLearner(CalculationRules $rules, array $units, string $session): array
    {
        $eliminatory = $rules->number('eliminatory', 7.0);
        $unitPass = $rules->number('unit_pass', 10.0);
        $semesterPass = $rules->number('semester_pass', 10.0);

        $ecSum = 0.0;
        $ecCredits = 0;
        $unitAverages = [];
        foreach ($units as $unit) {
            $sum = 0.0;
            $credits = 0;
            foreach ($unit['groups'] as $group) {
                $note = $this->elementNote($rules, $group['entries'], $session);
                if ($note === null) {
                    continue;
                }
                $sum += $note * $group['credits'];
                $credits += $group['credits'];
            }
            $ecSum += $sum;
            $ecCredits += $credits;
            $unitAverages[$unit['subject_id']] = $credits > 0 ? $rules->round($sum / $credits) : null;
        }
        $semester = $ecCredits > 0 ? $rules->round($ecSum / $ecCredits) : null;

        $earned = 0;
        $total = 0;
        $points = 0.0;
        $allAcquired = true;
        $compensated = false;
        $details = [];
        foreach ($units as $unit) {
            $average = $unitAverages[$unit['subject_id']];
            $total += $unit['credits'];
            $grade = $this->letter($average ?? 0.0);
            $status = 'not_acquired';
            if ($average !== null && $average >= $unitPass) {
                $status = 'acquired';
            } elseif ($average !== null && $average >= $eliminatory && $semester !== null && $semester >= $semesterPass) {
                $status = 'compensated';
                $compensated = true;
            }
            if ($status === 'not_acquired') {
                $allAcquired = false;
            } else {
                $earned += $unit['credits'];
            }
            $points += $grade['points'] * $unit['credits'];
            $details[$unit['subject_id']] = ['letter' => $grade['letter'], 'points' => $grade['points'], 'status' => $status, 'credits' => $unit['credits'], 'session' => $session];
        }
        $gpa = $total > 0 ? $rules->round($points / $total) : null;

        $proposal = match (true) {
            $allAcquired && $compensated => 'compensated',
            $allAcquired => 'admitted',
            $session === 'normal' => 'resit',
            default => 'adjourned',
        };

        return [
            'average' => $semester,
            'subjects' => $unitAverages,
            'credits_earned' => $earned,
            'credits_total' => $total,
            'mention' => $allAcquired && $semester !== null ? $rules->mention($semester) : null,
            'deliberation_proposal' => $rules->label($proposal),
            'units' => $details,
            'extras' => ['gpa' => $gpa],
        ];
    }

    public function decideYear(CalculationRules $rules, array $periods, array $context): array
    {
        $earned = 0;
        $total = 0;
        $sum = 0.0;
        foreach ($periods as $period) {
            $earned += $period['credits_earned'];
            $total += $period['credits_total'];
            $sum += ($period['average'] ?? 0.0) * $period['credits_total'];
        }
        $average = $total > 0 ? $rules->round($sum / $total) : null;
        $admitted = (int) $rules->number('year_admitted', 60.0);
        $debts = (int) $rules->number('year_debts', 45.0);
        $cumulative = (int) ($context['cumulative_credits'] ?? 0) + $earned;
        $enrolments = (int) ($context['enrolments_at_level'] ?? 1);
        $finalYear = ($context['final_year'] ?? false) === true;

        $decision = match (true) {
            $earned >= $admitted => 'admitted',
            $earned >= $debts => 'admitted_with_debts',
            $enrolments >= (int) $rules->number('max_enrolments', 2.0) => 'excluded',
            default => 'repeat',
        };
        $diploma = $finalYear && $decision === 'admitted' && $cumulative >= (int) $rules->number('final_credits', 180.0);

        return [
            'average' => $average,
            'credits_earned' => $earned,
            'credits_total' => $total,
            'decision' => $rules->label($decision),
            'extras' => ['cumulative_credits' => $cumulative, 'diploma_file' => $diploma],
        ];
    }

    /** @param list<Entry> $entries */
    private function elementNote(CalculationRules $rules, array $entries, string $session): ?float
    {
        $cc = $this->average($rules, $entries, (array) $rules->option('cc_kinds', ['cc']));
        $exam = $this->average($rules, $entries, (array) $rules->option('exam_kinds', ['exam']));
        if ($session === 'resit') {
            $resit = $this->average($rules, array_values(array_filter($entries, static fn (array $e): bool => $e['session'] === 'resit')), (array) $rules->option('resit_kinds', ['resit']));
            $exam = $resit ?? $exam;
        }
        if ($cc === null && $exam === null) {
            return null;
        }
        if ($cc === null || $exam === null) {
            return $rules->round((float) ($cc ?? $exam));
        }

        return $rules->round($rules->number('cc_weight', 0.4) * $cc + $rules->number('exam_weight', 0.6) * $exam);
    }

    /**
     * @param list<Entry> $entries
     * @param array<mixed> $kinds
     */
    private function average(CalculationRules $rules, array $entries, array $kinds): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($entries as $entry) {
            if (!in_array($entry['kind'], $kinds, true) || $entry['weight'] <= 0.0) {
                continue;
            }
            if ($entry['absence'] !== null) {
                if ($entry['effect'] !== CalculationRules::EFFECT_ZERO) {
                    continue;
                }
                $value = 0.0;
            } elseif ($entry['value'] === null || $entry['max'] <= 0.0) {
                continue;
            } else {
                $value = $entry['value'] / $entry['max'] * $rules->scale;
            }
            $sum += $value * $entry['weight'];
            $weights += $entry['weight'];
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }

    /** @return array{min: float, letter: string, points: float} */
    private function letter(float $average): array
    {
        foreach (self::LETTERS as $grade) {
            if ($average >= $grade['min']) {
                return $grade;
            }
        }

        return self::LETTERS[count(self::LETTERS) - 1];
    }
}
