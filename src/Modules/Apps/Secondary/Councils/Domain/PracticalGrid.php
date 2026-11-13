<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

use InvalidArgumentException;

/**
 * Évaluation pratique par grille de critères (EX-ATL-05, paramètres `secondary.workshop.*`) : note = points observés ÷ points de
 * la grille × base ; un manquement grave à la sécurité plafonne la note au plafond paramétré (pas de plafond s'il est absent).
 * Critères, points, base et plafond viennent de l'établissement. Note destinée à une épreuve du socle (lot E3, Workshops).
 */
final class PracticalGrid
{
    /**
     * @param list<array{code: string, points: float|int}> $criteria
     */
    public function __construct(private readonly array $criteria, private readonly float $base, private readonly ?float $capOnSevereBreach = null)
    {
        if ($criteria === [] || $base <= 0.0) {
            throw new InvalidArgumentException('Une grille a au moins un critère et une base positive.');
        }
        foreach ($criteria as $criterion) {
            if ((float) $criterion['points'] <= 0.0) {
                throw new InvalidArgumentException('Critère « ' . $criterion['code'] . ' » : points positifs attendus.');
            }
        }
    }

    /** @param array{base: float|int, cap_on_severe_breach?: float|int|null, criteria: list<array{code: string, points: float|int}>} $grid */
    public static function fromParameters(array $grid): self
    {
        $cap = $grid['cap_on_severe_breach'] ?? null;

        return new self($grid['criteria'], (float) $grid['base'], $cap === null ? null : (float) $cap);
    }

    /**
     * @param array<string, float|int> $observed points observés par code de critère (absent = 0)
     * @return array{score: float, uncapped: float, capped: bool, observed: float, total: float}
     */
    public function score(array $observed, bool $severeSafetyBreach = false): array
    {
        $total = 0.0;
        $sum = 0.0;
        foreach ($this->criteria as $criterion) {
            $points = (float) $criterion['points'];
            $value = (float) ($observed[$criterion['code']] ?? 0.0);
            if ($value < 0.0 || $value > $points) {
                throw new InvalidArgumentException('Critère « ' . $criterion['code'] . ' » : entre 0 et ' . $points . ' points.');
            }
            $total += $points;
            $sum += $value;
        }
        $uncapped = $sum / $total * $this->base;
        $capped = $severeSafetyBreach && $this->capOnSevereBreach !== null && $uncapped > $this->capOnSevereBreach;

        return ['score' => $capped ? (float) $this->capOnSevereBreach : $uncapped, 'uncapped' => $uncapped, 'capped' => $capped, 'observed' => $sum, 'total' => $total];
    }
}
