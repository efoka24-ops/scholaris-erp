<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Formation interne BTS/HND (CDC) : note de matière = 0,4 × moyenne des devoirs + 0,6 × examen semestriel ; moyenne semestrielle
 * = Σ(note × coefficient) ÷ Σ coefficients ; moyenne annuelle = moyenne des deux semestres ; passage en 2e année si moyenne
 * annuelle ≥ 10 (annual_pass_average), sinon redoublement ou réorientation sur décision du jury ; rang dans le groupe avec ex æquo.
 *
 * @phpstan-import-type Unit from RuleStrategy
 * @phpstan-import-type LearnerResult from RuleStrategy
 * @phpstan-import-type PeriodSummary from RuleStrategy
 * @phpstan-import-type YearDecision from RuleStrategy
 */
final class BtsHndCalculator
{
    /**
     * @param list<Unit> $units matières
     * @return LearnerResult
     */
    public function semester(CalculationRules $calculationRules, array $units, string $session = 'normal'): array
    {
        $rules = new Rules($calculationRules);
        $pass = $rules->number(['annual_pass_average', 'pass_mark'], 10.0);
        $sum = 0.0;
        $coefficients = 0.0;
        $subjects = [];
        $details = [];
        foreach ($units as $unit) {
            $entries = [];
            foreach ($unit['groups'] as $group) {
                array_push($entries, ...$group['entries']);
            }
            $mark = Marks::note($rules, $entries, $session, $rules->ccWeight($unit['code'], $unit['subject_id']));
            $subjects[$unit['subject_id']] = $mark['note'];
            $details[$unit['subject_id']] = [
                'code' => $unit['code'], 'name' => $unit['name'], 'coefficient' => $unit['coefficient'], 'cc' => $mark['cc'], 'exam' => $mark['exam'],
                'resit' => $mark['resit'], 'status' => $mark['note'] !== null && $mark['note'] >= $pass ? 'passed' : 'failed', 'session' => $session,
            ];
            if ($mark['note'] !== null && $unit['coefficient'] > 0) {
                $sum += $mark['note'] * $unit['coefficient'];
                $coefficients += $unit['coefficient'];
            }
        }
        $average = $coefficients > 0 ? $rules->round($sum / $coefficients) : null;
        $proposal = $average !== null && $average >= $pass ? 'admitted' : 'adjourned';

        return [
            'average' => $average,
            'subjects' => $subjects,
            'credits_earned' => 0,
            'credits_total' => 0,
            'mention' => $proposal === 'admitted' ? $rules->mention($average) : null,
            'deliberation_proposal' => $calculationRules->label($proposal),
            'units' => $details,
            'extras' => ['proposal_code' => $proposal, 'coefficients' => $coefficients],
        ];
    }

    /**
     * @param list<PeriodSummary> $periods
     * @param array<string, mixed> $context enrolments_at_level, derogation
     * @return YearDecision
     */
    public function year(CalculationRules $calculationRules, array $periods, array $context = []): array
    {
        $rules = new Rules($calculationRules);
        $averages = array_values(array_filter(array_map(static fn (array $p): ?float => $p['average'], $periods), static fn (?float $a): bool => $a !== null));
        $average = $averages === [] ? null : $rules->round(array_sum($averages) / count($averages));
        $pass = $rules->number(['annual_pass_average', 'pass_mark'], 10.0);
        $enrolments = (int) ($context['enrolments_at_level'] ?? 1);
        $maxEnrolments = (int) $rules->number(['max_consecutive_enrolments', 'max_enrolments'], 2.0);
        $decision = match (true) {
            $average !== null && $average >= $pass => 'admitted',
            $enrolments >= $maxEnrolments && ($context['derogation'] ?? false) !== true => 'excluded',
            default => 'repeat',
        };

        return [
            'average' => $average,
            'credits_earned' => 0,
            'credits_total' => 0,
            'decision' => $calculationRules->label($decision),
            'extras' => [
                'decision_code' => $decision, 'acquired_units' => [], 'missing_units' => [],
                'mention' => $decision === 'admitted' ? $rules->mention($average) : null,
            ],
        ];
    }
}
