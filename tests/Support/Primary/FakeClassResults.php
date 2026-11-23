<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Modules\Socle\Assessment\Contracts\ClassResults;

/**
 * Doublure de ClassResults : moyennes fictives fournies par le test (« période => élève => moyenne »), rang avec ex æquo calculé ici,
 * une matière fictive par élève. Une période inconnue donne des moyennes nulles (classe sans épreuve chiffrée).
 */
final class FakeClassResults implements ClassResults
{
    /** @var array<string, array<string, float|null>> */
    public array $averages = [];

    /** @var array<string, list<string>> */
    public array $roster = [];

    /**
     * Matières fictives d'un élève pour une période (« période => élève => matières ») ; sans entrée, une matière fictive `FR` à la moyenne générale.
     *
     * @var array<string, array<string, list<array{subject_id: string, code: string|null, name: string|null, average: float|null, coefficient?: float|null}>>>
     */
    public array $subjects = [];

    public function compute(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel = null): array
    {
        $values = $this->averages[$periodId] ?? [];
        $people = $this->roster[$structureId] ?? array_keys($values);
        $weighted = [];
        foreach ($people as $person) {
            $rows = $this->subjects[$periodId][$person] ?? [['subject_id' => 'sub-fr', 'code' => 'FR', 'name' => 'Français', 'average' => $values[$person] ?? null, 'coefficient' => 1.0]];
            $sum = 0.0;
            $coefficients = 0.0;
            foreach ($rows as $subject) {
                $average = $subject['average'];
                if ($average === null) {
                    continue;
                }
                $coefficient = $subject['coefficient'] ?? 1.0;
                if ($coefficient <= 0.0) {
                    $coefficient = 1.0;
                }
                $sum += $average * $coefficient;
                $coefficients += $coefficient;
            }
            $weighted[$person] = $coefficients > 0.0 ? round($sum / $coefficients, 2) : ($values[$person] ?? null);
        }
        $sorted = array_filter($weighted, static fn (?float $v): bool => $v !== null);
        arsort($sorted);
        $ranks = [];
        $position = 0;
        $previous = null;
        $rank = 0;
        foreach ($sorted as $person => $value) {
            ++$position;
            if ($value !== $previous) {
                $rank = $position;
            }
            $ranks[$person] = $rank;
            $previous = $value;
        }
        $out = [];
        foreach ($people as $person) {
            $average = $weighted[$person] ?? ($values[$person] ?? null);
            $rows = $this->subjects[$periodId][$person] ?? [['subject_id' => 'sub-fr', 'code' => 'FR', 'name' => 'Français', 'average' => $average, 'coefficient' => 1.0]];
            $out[] = [
                'person_id' => $person, 'average' => $average, 'rank' => $ranks[$person] ?? null, 'class_size' => count($people), 'mention' => null, 'rule_version' => 1,
                'subjects' => array_map(static fn (array $subject): array => [
                    'subject_id' => $subject['subject_id'], 'code' => $subject['code'] ?? null, 'name' => $subject['name'] ?? null, 'average' => $subject['average'] ?? null,
                    'coefficient' => $subject['coefficient'] ?? 1.0,
                ], $rows),
            ];
        }

        return $out;
    }
}
