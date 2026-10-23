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

    public function compute(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel = null): array
    {
        $values = $this->averages[$periodId] ?? [];
        $people = $this->roster[$structureId] ?? array_keys($values);
        $sorted = array_filter($values, static fn (?float $v): bool => $v !== null);
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
            $average = $values[$person] ?? null;
            $out[] = [
                'person_id' => $person, 'average' => $average, 'rank' => $ranks[$person] ?? null, 'class_size' => count($people), 'mention' => null, 'rule_version' => 1,
                'subjects' => [['subject_id' => 'sub-fr', 'code' => 'FR', 'name' => 'Français', 'average' => $average]],
            ];
        }

        return $out;
    }
}
