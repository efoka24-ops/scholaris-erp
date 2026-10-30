<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Domain;

/**
 * Moteur de calcul déterministe (EX-S09) : même entrée et mêmes règles donnent toujours le même résultat.
 * Fonctions pures, sans accès à la base : les données sont préparées par la couche application.
 *
 * Une matière est un ensemble de groupes (un par élément du référentiel, ou un groupe unique) ; un groupe est
 * une liste de notes pondérées. Toute note est ramenée au barème avant d'être pondérée.
 *
 * Session de rattrapage (S7) : dans un groupe, une note de session « resit » qui compte remplace les notes de la session
 * normale. Les étiquettes de décision et les effets des codes d'absence viennent des règles (S1, S9).
 *
 * @phpstan-type Entry array{value: float|null, max: float, weight: float, absence: string|null, session?: string}
 * @phpstan-type Group array{coefficient: float, entries: list<Entry>}
 * @phpstan-type Subject array{subject_id: string, kind: string, coefficient: float, credits: int, groups: list<Group>}
 */
final class RuleEngine
{
    public const ADMITTED = 'Admis';
    public const COMPENSATED = 'Admis par compensation';
    public const RESIT = 'Rattrapage';
    public const ADJOURNED = 'Ajourné';

    /**
     * @param list<Subject> $subjects
     * @return array{average: float|null, subjects: array<string, float|null>, credits_earned: int, credits_total: int, mention: string|null, deliberation_proposal: string}
     */
    public function compute(RuleSet $rules, array $subjects): array
    {
        $averages = [];
        foreach ($subjects as $subject) {
            $averages[$subject['subject_id']] = $this->subjectAverage($rules, $subject);
        }

        $sum = 0.0;
        $weights = 0.0;
        foreach ($subjects as $subject) {
            $average = $averages[$subject['subject_id']];
            $weight = $this->weightOf($rules, $subject);
            if ($average === null || $weight <= 0.0) {
                continue;
            }
            $sum += $average * $weight;
            $weights += $weight;
        }
        $overall = $weights > 0.0 ? $sum / $weights : null;

        [$earned, $total, $allDirect, $allCompensable] = $this->credits($rules, $subjects, $averages, $overall);

        return [
            'average' => $overall === null ? null : $this->round($rules, $overall),
            'subjects' => array_map(fn (?float $a): ?float => $a === null ? null : $this->round($rules, $a), $averages),
            'credits_earned' => $earned,
            'credits_total' => $total,
            'mention' => $overall === null ? null : $this->mention($rules, $this->round($rules, $overall)),
            'deliberation_proposal' => $this->deliberationProposal($rules, $overall, $allDirect, $allCompensable),
        ];
    }

    /**
     * Moyenne d'une matière ramenée au barème (non arrondie) ; null si aucune note ne compte.
     *
     * @param Subject $subject
     */
    public function subjectAverage(RuleSet $rules, array $subject): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($subject['groups'] as $group) {
            $groupAverage = $this->groupAverage($rules, $group['entries']);
            if ($groupAverage === null) {
                continue;
            }
            $coefficient = $group['coefficient'] > 0.0 ? $group['coefficient'] : 1.0;
            $sum += $groupAverage * $coefficient;
            $weights += $coefficient;
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }

    /** @param list<Entry> $entries */
    private function groupAverage(RuleSet $rules, array $entries): ?float
    {
        $resit = array_values(array_filter($entries, fn (array $e): bool => ($e['session'] ?? 'normal') === 'resit' && $this->normalise($rules, $e) !== null && $e['weight'] > 0.0));
        if ($resit !== []) {
            $entries = $resit;
        } else {
            $entries = array_values(array_filter($entries, static fn (array $e): bool => ($e['session'] ?? 'normal') !== 'resit'));
        }
        $sum = 0.0;
        $weights = 0.0;
        foreach ($entries as $entry) {
            $normalised = $this->normalise($rules, $entry);
            if ($normalised === null || $entry['weight'] <= 0.0) {
                continue;
            }
            $sum += $normalised * $entry['weight'];
            $weights += $entry['weight'];
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }

    /**
     * Note ramenée au barème, ou null si elle ne compte pas : absence selon l'effet déclaré de son code (par défaut :
     * justifiée exclue, non justifiée à zéro ou exclue selon la règle ; « retake » est exclue en attendant l'épreuve de
     * remplacement), note manquante (exclue).
     *
     * @param Entry $entry
     */
    private function normalise(RuleSet $rules, array $entry): ?float
    {
        if ($entry['absence'] !== null) {
            return $rules->absenceEffect($entry['absence']) === RuleSet::ZERO ? 0.0 : null;
        }
        if ($entry['value'] === null || $entry['max'] <= 0.0) {
            return null;
        }

        return $entry['value'] / $entry['max'] * $rules->scale;
    }

    /** @param Subject $subject */
    private function weightOf(RuleSet $rules, array $subject): float
    {
        if ($rules->weighting === RuleSet::BY_CREDITS && $subject['credits'] > 0) {
            return (float) $subject['credits'];
        }

        return $subject['coefficient'];
    }

    /**
     * @param list<Subject> $subjects
     * @param array<string, float|null> $averages
     * @return array{0: int, 1: int, 2: bool, 3: bool} crédits acquis, crédits totaux, tout acquis seul, tout compensable
     */
    private function credits(RuleSet $rules, array $subjects, array $averages, ?float $overall): array
    {
        $earned = 0;
        $total = 0;
        $allDirect = true;
        $allCompensable = true;
        $compensation = $overall !== null && $this->round($rules, $overall) >= $rules->passMark;

        foreach ($subjects as $subject) {
            if ($subject['credits'] <= 0) {
                continue;
            }
            $total += $subject['credits'];
            $average = $averages[$subject['subject_id']];
            $rounded = $average === null ? null : $this->round($rules, $average);
            if ($rounded !== null && $rounded >= $rules->capitalizeFrom) {
                $earned += $subject['credits'];
                continue;
            }
            $allDirect = false;
            if ($rounded !== null && $rounded >= $rules->compensationFrom) {
                if ($compensation) {
                    $earned += $subject['credits'];
                }
                continue;
            }
            $allCompensable = false;
        }

        return [$earned, $total, $allDirect, $allCompensable];
    }

    private function deliberationProposal(RuleSet $rules, ?float $overall, bool $allDirect, bool $allCompensable): string
    {
        if ($overall === null) {
            return $rules->decisionLabel('adjourned');
        }
        $overall = $this->round($rules, $overall);
        if ($overall >= $rules->passMark && $allDirect) {
            return $rules->decisionLabel('admitted');
        }
        if ($overall >= $rules->passMark && $allCompensable) {
            return $rules->decisionLabel('compensated');
        }

        return $rules->decisionLabel($overall >= $rules->resitFrom ? 'resit' : 'adjourned');
    }

    public function round(RuleSet $rules, float $value): float
    {
        return round($value, $rules->rounding, PHP_ROUND_HALF_UP);
    }

    /** Appréciation automatique par seuils : le plus haut seuil atteint. */
    public function mention(RuleSet $rules, float $average): ?string
    {
        foreach ($rules->mentions as $mention) {
            if ($average >= $mention['min']) {
                return $mention['label'];
            }
        }

        return null;
    }

    /** Proposition du conseil de classe : première règle déclarée dont toutes les conditions sont remplies. */
    public function councilProposal(RuleSet $rules, ?float $average, int $unjustifiedAbsences): ?string
    {
        foreach ($rules->councilProposals as $rule) {
            if (isset($rule['min_unjustified']) && $unjustifiedAbsences < $rule['min_unjustified']) {
                continue;
            }
            if (isset($rule['min_average']) && ($average === null || $average < $rule['min_average'])) {
                continue;
            }
            if (isset($rule['below_average']) && ($average === null || $average >= $rule['below_average'])) {
                continue;
            }
            if (!isset($rule['min_unjustified']) && !isset($rule['min_average']) && !isset($rule['below_average'])) {
                continue;
            }

            return $rule['label'];
        }

        return null;
    }

    /**
     * Rangs par ordre décroissant de moyenne ; les ex æquo partagent le même rang (1, 2, 2, 4). Sans moyenne : pas de rang.
     *
     * @param array<string, float|null> $averages identifiant => moyenne
     * @return array<string, int|null>
     */
    public function ranks(array $averages): array
    {
        $ranked = array_filter($averages, static fn (?float $a): bool => $a !== null);
        uasort($ranked, static fn (float $a, float $b): int => $b <=> $a);

        $out = array_fill_keys(array_keys($averages), null);
        $position = 0;
        $rank = 0;
        $previous = null;
        foreach ($ranked as $id => $average) {
            ++$position;
            if ($previous === null || abs($average - $previous) > 1e-9) {
                $rank = $position;
            }
            $out[$id] = $rank;
            $previous = $average;
        }

        return $out;
    }
}
