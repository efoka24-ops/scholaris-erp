<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Domain;

/**
 * Rang et statistiques par matière d'une classe (SS4, paramètre assessment.subject_ranks). Fonction pure : reçoit le
 * détail par matière de chaque élève (liste d'entrées portant subject_id et average) et les moyennes générales, rend
 * le détail enrichi de rank_in_subject, class_subject_avg, class_subject_min, class_subject_max (ex æquo : même rang,
 * comme RuleEngine::ranks) et les statistiques de classe à ajouter aux valeurs calculées (class_avg, class_min, class_max).
 */
final class SubjectStatistics
{
    public function __construct(private readonly RuleEngine $engine)
    {
    }

    /**
     * @param array<string, list<array<string, mixed>>> $details détail par élève
     * @param array<string, float|null> $averages moyenne générale par élève
     * @return array{details: array<string, list<array<string, mixed>>>, class: array{class_avg: float|null, class_min: float|null, class_max: float|null}}
     */
    public function apply(RuleSet $rules, array $details, array $averages): array
    {
        $bySubject = [];
        foreach ($details as $personId => $entries) {
            foreach ($entries as $entry) {
                $subjectId = isset($entry['subject_id']) ? (string) $entry['subject_id'] : '';
                if ($subjectId === '') {
                    continue;
                }
                $value = $entry['average'] ?? null;
                $bySubject[$subjectId][(string) $personId] = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
            }
        }

        $stats = [];
        foreach ($bySubject as $subjectId => $values) {
            $stats[$subjectId] = ['ranks' => $this->engine->ranks($values)] + $this->spread($rules, $values);
        }

        $out = [];
        foreach ($details as $personId => $entries) {
            $enriched = [];
            foreach ($entries as $entry) {
                $subjectId = isset($entry['subject_id']) ? (string) $entry['subject_id'] : '';
                if ($subjectId !== '' && isset($stats[$subjectId])) {
                    $entry['rank_in_subject'] = $stats[$subjectId]['ranks'][(string) $personId] ?? null;
                    $entry['class_subject_avg'] = $stats[$subjectId]['avg'];
                    $entry['class_subject_min'] = $stats[$subjectId]['min'];
                    $entry['class_subject_max'] = $stats[$subjectId]['max'];
                }
                $enriched[] = $entry;
            }
            $out[(string) $personId] = $enriched;
        }
        $class = $this->spread($rules, $averages);

        return ['details' => $out, 'class' => ['class_avg' => $class['avg'], 'class_min' => $class['min'], 'class_max' => $class['max']]];
    }

    /**
     * @param array<string, float|null> $values
     * @return array{avg: float|null, min: float|null, max: float|null}
     */
    private function spread(RuleSet $rules, array $values): array
    {
        $known = array_values(array_filter($values, static fn (?float $v): bool => $v !== null));
        if ($known === []) {
            return ['avg' => null, 'min' => null, 'max' => null];
        }

        return ['avg' => $this->engine->round($rules, array_sum($known) / count($known)), 'min' => min($known), 'max' => max($known)];
    }
}
