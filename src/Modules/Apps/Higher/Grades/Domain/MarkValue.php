<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Domain;

/**
 * Valeur d'une cellule de la grille : une note (RG-NOT-1 : 0 au barème, au pas déclaré, hors barème refusé) OU un code d'absence
 * (EX-NOT-03 : code ou alias d'import ABJ, ABI, NA, FR…), ou rien (cellule vide).
 */
final class MarkValue
{
    private function __construct(public readonly ?float $score, public readonly ?string $absence)
    {
    }

    public static function empty(): self
    {
        return new self(null, null);
    }

    public function isEmpty(): bool
    {
        return $this->score === null && $this->absence === null;
    }

    /**
     * @param array<string, array{effect: ?string, label: string, aliases: list<string>}> $codes
     * @throws \InvalidArgumentException message en français
     */
    public static function of(mixed $score, mixed $absence, float $maxScore, float $step, array $codes): self
    {
        if ($score !== null && $absence !== null && $absence !== '') {
            throw new \InvalidArgumentException('Une note OU un code d\'absence, pas les deux.');
        }
        if ($absence !== null && $absence !== '') {
            return new self(null, self::code($absence, $codes));
        }
        if ($score === null || $score === '') {
            return self::empty();
        }
        if (is_string($score)) {
            $normalized = str_replace(',', '.', trim($score));
            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $normalized) !== 1) {
                throw new \InvalidArgumentException('Note invalide : « ' . $score . ' ».');
            }
            $score = (float) $normalized;
        }
        if (!is_int($score) && !is_float($score)) {
            throw new \InvalidArgumentException('Note numérique attendue.');
        }
        $value = (float) $score;
        if ($value < 0 || $value > $maxScore + 1e-9) {
            throw new \InvalidArgumentException(sprintf('Note hors barème : %s (0 à %s).', self::format($value), self::format($maxScore)));
        }
        $steps = $value / $step;
        if (abs($steps - round($steps)) > 1e-6) {
            throw new \InvalidArgumentException(sprintf('Note au pas de %s attendue (%s refusée).', self::format($step), self::format($value)));
        }

        return new self(round($value, 2), null);
    }

    /**
     * Cellule brute d'import CSV : note (virgule ou point décimal), code ou alias d'absence, vide.
     *
     * @param array<string, array{effect: ?string, label: string, aliases: list<string>}> $codes
     */
    public static function fromCsv(string $raw, float $maxScore, float $step, array $codes): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::empty();
        }
        if (preg_match('/^[A-Za-z_]+$/', $raw) === 1) {
            return self::of(null, $raw, $maxScore, $step, $codes);
        }

        return self::of($raw, null, $maxScore, $step, $codes);
    }

    /** @param array<string, array{effect: ?string, label: string, aliases: list<string>}> $codes */
    private static function code(mixed $absence, array $codes): string
    {
        if (!is_string($absence)) {
            throw new \InvalidArgumentException('Code d\'absence invalide.');
        }
        $needle = trim($absence);
        foreach ($codes as $code => $declared) {
            if (strtolower($needle) === $code || in_array(strtoupper($needle), $declared['aliases'], true)) {
                return $code;
            }
        }
        $known = [];
        foreach ($codes as $declared) {
            $known[] = $declared['aliases'][0] ?? '';
        }

        throw new \InvalidArgumentException('Code d\'absence inconnu : « ' . $needle . ' » (codes : ' . implode(', ', array_filter($known)) . ').');
    }

    public static function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
