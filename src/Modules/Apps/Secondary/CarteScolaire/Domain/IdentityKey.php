<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain;

/**
 * Clé de rapprochement d'identité (pure) : nom et prénoms en majuscules sans accents ni ponctuation, mots triés (l'ordre nom,
 * prénoms varie d'un fichier à l'autre), suivis de la date de naissance AAAA-MM-JJ. Sert aussi à normaliser les libellés.
 */
final class IdentityKey
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ];

    public static function text(string $value): string
    {
        $lower = strtr(mb_strtolower(trim($value)), self::ACCENTS);
        $clean = (string) preg_replace('/[^a-z0-9\']+/', ' ', $lower);

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    public static function name(string $value): string
    {
        $words = array_values(array_filter(explode(' ', strtoupper(str_replace('\'', ' ', self::text($value)))), static fn (string $w): bool => $w !== ''));
        sort($words);

        return implode(' ', $words);
    }

    public static function of(string $fullName, string $birthDate): string
    {
        return self::name($fullName) . '|' . $birthDate;
    }
}
