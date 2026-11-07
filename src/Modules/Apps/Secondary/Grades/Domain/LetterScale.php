<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Grades\Domain;

/**
 * Correspondance note et lettre des bulletins anglophones (EX-NOT-08, Q7), lue du paramètre `secondary.grades.letter_scale` :
 * liste de tranches `{min, letter}` sur le barème de la note. Table vide = correspondance désactivée (aucune lettre). Aucune tranche
 * n'est codée ici : l'établissement saisit les siennes.
 */
final class LetterScale
{
    /** @param list<array{min: float, letter: string}> $bands triées par minimum décroissant */
    private function __construct(public readonly array $bands)
    {
    }

    /**
     * Tranches valides du paramètre, les autres sont ignorées (une valeur mal formée ne bloque jamais la saisie).
     */
    public static function fromSetting(mixed $raw): self
    {
        $bands = [];
        if (is_array($raw) && array_is_list($raw)) {
            foreach ($raw as $band) {
                if (!is_array($band) || !isset($band['min'], $band['letter']) || !is_numeric($band['min']) || !is_string($band['letter'])) {
                    continue;
                }
                $letter = trim($band['letter']);
                if ($letter === '' || preg_match('/^[A-Za-z][A-Za-z0-9+-]{0,3}$/', $letter) !== 1 || (float) $band['min'] < 0) {
                    continue;
                }
                $bands[] = ['min' => (float) $band['min'], 'letter' => $letter];
            }
        }
        usort($bands, static fn (array $a, array $b): int => $b['min'] <=> $a['min']);

        return new self($bands);
    }

    public function enabled(): bool
    {
        return $this->bands !== [];
    }

    /** Lettre de la première tranche atteinte, null si la table est vide, la note absente ou sous toutes les tranches. */
    public function letterFor(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }
        foreach ($this->bands as $band) {
            if ($score >= $band['min']) {
                return $band['letter'];
            }
        }

        return null;
    }

    /** @return list<array{min: float, letter: string}> */
    public function toArray(): array
    {
        return $this->bands;
    }
}
