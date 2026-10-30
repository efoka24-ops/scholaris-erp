<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Domain;

/**
 * Pratique professionnelle des établissements techniques (option `practical` de la version de règles) : matières concernées
 * (par type de matière `subject_kinds` ou par code `subject_codes`), moyenne minimale `min` (alerte et refus d'admission en fin
 * d'année), passage conditionnel `conditional_allowed`. Option absente : aucune matière de pratique, aucune alerte.
 */
final class PracticalSubjects
{
    public const OPTION = 'practical';

    /**
     * @param list<string> $kinds
     * @param list<string> $codes
     */
    private function __construct(
        public readonly array $kinds,
        public readonly array $codes,
        public readonly ?float $min,
        public readonly bool $conditionalAllowed,
    ) {
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(array $options): self
    {
        $practical = $options[self::OPTION] ?? null;
        if (!is_array($practical)) {
            return new self([], [], null, false);
        }
        $min = $practical['min'] ?? null;

        return new self(
            self::strings($practical['subject_kinds'] ?? []),
            self::strings($practical['subject_codes'] ?? []),
            is_int($min) || is_float($min) ? (float) $min : null,
            ($practical['conditional_allowed'] ?? false) === true,
        );
    }

    public function covers(?string $kind, ?string $code): bool
    {
        return ($kind !== null && in_array($kind, $this->kinds, true)) || ($code !== null && in_array($code, $this->codes, true));
    }

    public function isBelow(?float $average): bool
    {
        return $average !== null && $this->min !== null && $average < $this->min;
    }

    /** @return list<string> */
    public static function errors(mixed $practical): array
    {
        if (!is_array($practical)) {
            return ['« practical » est un objet {subject_kinds, subject_codes, min, conditional_allowed}.'];
        }
        $errors = [];
        foreach (['subject_kinds', 'subject_codes'] as $key) {
            $list = $practical[$key] ?? [];
            if (!is_array($list) || !array_is_list($list) || count(array_filter($list, 'is_string')) !== count($list)) {
                $errors[] = '« practical.' . $key . ' » est une liste de textes.';
            }
        }
        if (array_key_exists('min', $practical) && !is_int($practical['min']) && !is_float($practical['min'])) {
            $errors[] = '« practical.min » est un nombre.';
        }

        return $errors;
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
