<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Calcul LMD du CDC (« Règles de calcul »), fonctions pures, arrondi au centième à chaque étape :
 *  F1 note d'EC = poids CC × CC + poids examen × examen (40/60, modifiable par EC ; rattrapage = remplace l'examen, CC conservé) ;
 *  F2 moyenne d'UE = Σ(note EC × crédits EC) ÷ Σ crédits EC (plafond facultatif après rattrapage) ;
 *  F3 moyenne de semestre = Σ(note EC × crédits EC) ÷ Σ crédits (30 en LMD, decisions C4) ; points de jury (≤ 0,5) ajoutés ;
 *  UE acquise si moyenne ≥ 10 ; compensée si moyenne ≥ note éliminatoire de l'UE et semestre ≥ 10 ; sinon non acquise ;
 *  F5 MGP = Σ(points × crédits UE) ÷ Σ crédits ; F4 moyenne annuelle ; F6 crédits acquis ; décisions de fin d'année.
 *
 * @phpstan-import-type Unit from RuleStrategy
 * @phpstan-import-type LearnerResult from RuleStrategy
 * @phpstan-import-type PeriodSummary from RuleStrategy
 * @phpstan-import-type YearDecision from RuleStrategy
 */
final class LmdCalculator
{
    public const ACQUIRED = 'acquired';
    public const COMPENSATED = 'compensated';
    public const CAPITALIZED = 'capitalized';
    public const NOT_ACQUIRED = 'not_acquired';

    /**
     * @param list<Unit> $units
     * @param float $juryPoints points de jury accordés (plafonnés par jury_points_max)
     * @return LearnerResult
     */
    public function semester(CalculationRules $calculationRules, array $units, string $session = 'normal', float $juryPoints = 0.0): array
    {
        $rules = new Rules($calculationRules);
        $unitPass = $rules->number(['unit_pass_mark', 'unit_pass'], 10.0);
        $threshold = $rules->number(['compensation_threshold', 'semester_pass'], 10.0);
        $compensation = $rules->flag('compensation_enabled', true);
        $cap = $session === 'resit' ? $rules->nullableNumber('resit_unit_cap') : null;
        $grid = LetterGrid::from($calculationRules->option('letter_grid'));

        $ecSum = 0.0;
        $ecCredits = 0;
        $averages = [];
        $elements = [];
        foreach ($units as $unit) {
            $sum = 0.0;
            $credits = 0;
            $resat = false;
            $complete = true;
            foreach ($unit['groups'] as $group) {
                $mark = Marks::note($rules, $group['entries'], $session, $rules->ccWeight($group['code'], $group['element_id']));
                $elements[$unit['subject_id']][] = [
                    'element_id' => $group['element_id'], 'code' => $group['code'], 'credits' => $group['credits'],
                    'cc' => $mark['cc'], 'exam' => $mark['exam'], 'resit' => $mark['resit'], 'note' => $mark['note'],
                ];
                if ($mark['note'] === null) {
                    $complete = false;
                    continue;
                }
                $resat = $resat || $mark['resat'];
                $sum += $mark['note'] * $group['credits'];
                $credits += $group['credits'];
            }
            $ecSum += $sum;
            $ecCredits += $credits;
            $average = $credits > 0 ? $rules->round($sum / $credits) : null;
            if ($average !== null && $resat && $cap !== null) {
                $average = min($average, $cap);
            }
            $averages[$unit['subject_id']] = ['average' => $average, 'complete' => $complete && $credits > 0];
        }
        $raw = $ecCredits > 0 ? $rules->round($ecSum / $ecCredits) : null;
        $points = max(0.0, min($juryPoints, $rules->number(['jury_points_max'], 0.5)));
        $semester = $raw === null ? null : $rules->round(min($rules->scale(), $raw + $points));

        $earned = 0;
        $total = 0;
        $gpaSum = 0.0;
        $allAcquired = true;
        $compensated = false;
        $details = [];
        $subjects = [];
        foreach ($units as $unit) {
            $id = $unit['subject_id'];
            $average = $averages[$id]['average'];
            $subjects[$id] = $average;
            $eliminatory = $rules->eliminatory($unit['code'], $id);
            $grade = $grid->grade($average ?? 0.0);
            $status = self::NOT_ACQUIRED;
            if ($average !== null && $averages[$id]['complete'] && $average >= $unitPass) {
                $status = self::ACQUIRED;
            } elseif ($compensation && $average !== null && $averages[$id]['complete'] && $average >= $eliminatory && $semester !== null && $semester >= $threshold) {
                $status = self::COMPENSATED;
                $compensated = true;
            }
            $total += $unit['credits'];
            if ($status === self::NOT_ACQUIRED) {
                $allAcquired = false;
            } else {
                $earned += $unit['credits'];
            }
            $gpaSum += $grade['points'] * $unit['credits'];
            $details[$id] = [
                'code' => $unit['code'], 'name' => $unit['name'], 'letter' => $grade['letter'], 'points' => $grade['points'], 'status' => $status,
                'credits' => $unit['credits'], 'credits_earned' => $status === self::NOT_ACQUIRED ? 0 : $unit['credits'], 'eliminatory' => $eliminatory,
                'session' => $session, 'elements' => $elements[$id] ?? [],
            ];
        }
        $gpa = $total > 0 ? $rules->round($gpaSum / $total) : null;
        $proposal = match (true) {
            $allAcquired && $compensated => 'compensated',
            $allAcquired => 'admitted',
            $session === 'normal' && $rules->flag('resit_enabled', true) => 'resit',
            default => 'adjourned',
        };

        return [
            'average' => $semester,
            'subjects' => $subjects,
            'credits_earned' => $earned,
            'credits_total' => $total,
            'mention' => $allAcquired && $semester !== null ? $rules->mention($semester) : null,
            'deliberation_proposal' => $calculationRules->label($proposal),
            'units' => $details,
            'extras' => ['gpa' => $gpa, 'raw_average' => $raw, 'jury_points' => $points, 'proposal_code' => $proposal],
        ];
    }

    /**
     * Décision de fin d'année (60 crédits possibles) : 60 admis ; 45 à 59 admis avec dettes ; moins de 45 redouble ; au-delà du
     * nombre d'inscriptions autorisées dans le niveau (RG-INS-5, défaut 2) exclu pour insuffisance sauf dérogation ; dernière
     * année de licence avec 180 crédits cumulés : admis et dossier de diplôme.
     *
     * Contexte : enrolments_at_level (rang de l'inscription dans le niveau), derogation (bool), cumulative_credits (avant l'année),
     * final_year (bool), capitalized_units (codes d'UE acquises les années précédentes).
     *
     * @param list<PeriodSummary> $periods
     * @param array<string, mixed> $context
     * @return YearDecision
     */
    public function year(CalculationRules $calculationRules, array $periods, array $context = []): array
    {
        $rules = new Rules($calculationRules);
        $earned = 0;
        $total = 0;
        $sum = 0.0;
        $acquired = [];
        $missing = [];
        $capitalized = array_values(array_map('strval', (array) ($context['capitalized_units'] ?? [])));
        foreach ($periods as $period) {
            $earned += $period['credits_earned'];
            $total += $period['credits_total'];
            $sum += ($period['average'] ?? 0.0) * $period['credits_total'];
            foreach ($period['units'] as $key => $unit) {
                $code = (string) ($unit['code'] ?? $key);
                if (($unit['status'] ?? self::NOT_ACQUIRED) !== self::NOT_ACQUIRED || in_array($code, $capitalized, true)) {
                    $acquired[] = $code;
                } else {
                    $missing[] = $code;
                }
            }
        }
        // F4 : (MoyS1 × créditsS1 + MoyS2 × créditsS2) ÷ (créditsS1 + créditsS2).
        $average = $total > 0 ? $rules->round($sum / $total) : null;
        $admitted = (int) $rules->number(['progression_admitted_credits', 'year_admitted'], 60.0);
        $debts = (int) $rules->number(['progression_debts_min_credits', 'year_debts'], 45.0);
        $maxEnrolments = (int) $rules->number(['max_consecutive_enrolments', 'max_enrolments'], 2.0);
        $enrolments = (int) ($context['enrolments_at_level'] ?? 1);
        $derogation = ($context['derogation'] ?? false) === true;
        $cumulative = (int) ($context['cumulative_credits'] ?? 0) + $earned;
        $finalYear = ($context['final_year'] ?? false) === true;

        $decision = match (true) {
            $earned >= $admitted => 'admitted',
            $earned >= $debts => 'admitted_with_debts',
            $enrolments >= $maxEnrolments && !$derogation => 'excluded',
            default => 'repeat',
        };
        $finalCredits = (int) $rules->number(['final_credits'], 180.0);
        $diploma = $finalYear && $decision === 'admitted' && $cumulative >= $finalCredits;

        return [
            'average' => $average,
            'credits_earned' => $earned,
            'credits_total' => $total,
            'decision' => $calculationRules->label($decision),
            'extras' => [
                'decision_code' => $decision, 'cumulative_credits' => $cumulative, 'diploma_file' => $diploma,
                'acquired_units' => array_values(array_unique($acquired)), 'missing_units' => array_values(array_unique(array_diff($missing, $acquired))),
                'mention' => in_array($decision, ['admitted', 'admitted_with_debts'], true) && $average !== null ? $rules->mention($average) : null,
            ],
        ];
    }

    /**
     * Cas limites (EX-DEL-02) : valeurs à moins de `margin` point sous un seuil (semestre, validation et note éliminatoire d'UE,
     * mentions). Sert à la simulation avant le jury.
     *
     * @param LearnerResult $result
     * @return list<array{kind: string, unit: string|null, value: float, threshold: float, gap: float}>
     */
    public function borderline(CalculationRules $calculationRules, array $result, float $margin = 0.5): array
    {
        $rules = new Rules($calculationRules);
        $out = [];
        $near = static fn (?float $value, float $threshold): bool => $value !== null && $value < $threshold && $threshold - $value <= $margin + 1e-9;
        $semester = $result['average'];
        $threshold = $rules->number(['compensation_threshold', 'semester_pass'], 10.0);
        if ($near($semester, $threshold)) {
            $out[] = ['kind' => 'semester', 'unit' => null, 'value' => (float) $semester, 'threshold' => $threshold, 'gap' => $rules->round($threshold - (float) $semester)];
        }
        foreach ($rules->mentionThresholds() as $mention) {
            if ($mention > $threshold && $near($semester, $mention)) {
                $out[] = ['kind' => 'mention', 'unit' => null, 'value' => (float) $semester, 'threshold' => $mention, 'gap' => $rules->round($mention - (float) $semester)];
            }
        }
        $unitPass = $rules->number(['unit_pass_mark', 'unit_pass'], 10.0);
        foreach ($result['units'] ?? [] as $id => $unit) {
            $average = $result['subjects'][$id] ?? null;
            $code = is_string($unit['code'] ?? null) ? $unit['code'] : (string) $id;
            if (($unit['status'] ?? null) !== self::ACQUIRED && $near($average, $unitPass)) {
                $out[] = ['kind' => 'unit_pass', 'unit' => $code, 'value' => (float) $average, 'threshold' => $unitPass, 'gap' => $rules->round($unitPass - (float) $average)];
            }
            $eliminatory = (float) ($unit['eliminatory'] ?? $rules->eliminatory($code));
            if ($near($average, $eliminatory)) {
                $out[] = ['kind' => 'eliminatory', 'unit' => $code, 'value' => (float) $average, 'threshold' => $eliminatory, 'gap' => $rules->round($eliminatory - (float) $average)];
            }
        }

        return $out;
    }
}
