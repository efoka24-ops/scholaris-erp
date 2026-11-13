<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

/**
 * Grille des lettres et points (CDC, « Règles de calcul ») : grille d'exemple par défaut, remplaçable par la grille de la
 * tutelle (option `letter_grid` des règles : liste de {min, letter, points}, de la plus haute à la plus basse).
 */
final class LetterGrid
{
    public const DEFAULT = [
        ['min' => 16.0, 'letter' => 'A+', 'points' => 4.0], ['min' => 15.0, 'letter' => 'A', 'points' => 3.7],
        ['min' => 14.0, 'letter' => 'B+', 'points' => 3.3], ['min' => 13.0, 'letter' => 'B', 'points' => 3.0],
        ['min' => 12.0, 'letter' => 'B-', 'points' => 2.7], ['min' => 11.0, 'letter' => 'C+', 'points' => 2.3],
        ['min' => 10.0, 'letter' => 'C', 'points' => 2.0], ['min' => 9.0, 'letter' => 'C-', 'points' => 1.7],
        ['min' => 8.0, 'letter' => 'D+', 'points' => 1.3], ['min' => 7.0, 'letter' => 'D', 'points' => 1.0],
        ['min' => 0.0, 'letter' => 'F', 'points' => 0.0],
    ];

    /** @param list<array{min: float, letter: string, points: float}> $rows */
    private function __construct(private readonly array $rows)
    {
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /** Grille fournie par les règles, sinon la grille d'exemple. Une grille invalide n'est jamais utilisée (voir errors()). */
    public static function from(mixed $raw): self
    {
        return is_array($raw) && $raw !== [] && self::errors($raw) === [] ? new self(self::normalize($raw)) : self::default();
    }

    /** @return list<string> */
    public static function errors(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return ['letter_grid doit être une liste non vide de {min, letter, points}.'];
        }
        $previous = INF;
        foreach ($raw as $i => $row) {
            if (!is_array($row) || !isset($row['min'], $row['letter'], $row['points']) || !is_numeric($row['min']) || !is_numeric($row['points'])
                || !is_string($row['letter']) || $row['letter'] === '') {
                return [sprintf('letter_grid[%d] : {min, letter, points} attendus.', $i)];
            }
            if ((float) $row['min'] >= $previous) {
                return ['letter_grid doit aller de la note minimale la plus haute à la plus basse.'];
            }
            $previous = (float) $row['min'];
        }
        if ($previous > 0.0) {
            return ['letter_grid doit couvrir la note 0.'];
        }

        return [];
    }

    /**
     * @param array<mixed> $raw
     * @return list<array{min: float, letter: string, points: float}>
     */
    private static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            /** @var array{min: int|float|string, letter: string, points: int|float|string} $row */
            $out[] = ['min' => (float) $row['min'], 'letter' => $row['letter'], 'points' => (float) $row['points']];
        }

        return $out;
    }

    /** @return array{letter: string, points: float} */
    public function grade(float $average): array
    {
        foreach ($this->rows as $row) {
            if ($average >= $row['min']) {
                return ['letter' => $row['letter'], 'points' => $row['points']];
            }
        }
        $last = $this->rows[count($this->rows) - 1];

        return ['letter' => $last['letter'], 'points' => $last['points']];
    }

    /** @return list<array{min: float, letter: string, points: float}> */
    public function rows(): array
    {
        return $this->rows;
    }
}
