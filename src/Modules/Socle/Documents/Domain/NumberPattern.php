<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

/**
 * Gabarit de numéro configurable par type. Jetons : {CODE} code établissement, {AA} / {AAAA} année,
 * {LETTRE} lettre du type, {N4} à {N9} compteur continu sur 4 à 9 chiffres, {ECOLE} code de l'école interne
 * fourni à l'émission (S16, ex. REL-{ECOLE}-{AAAA}-{N6} donne REL-EC-2026-000315).
 */
final class NumberPattern
{
    public const DEFAULT = '{CODE}-{AA}-{LETTRE}-{N6}';

    private const TOKEN = '/\{(CODE|AA|AAAA|LETTRE|ECOLE|N[1-9])\}/';

    public static function isValid(string $pattern): bool
    {
        if ($pattern === '' || strlen($pattern) > 80 || preg_match('/\{N[1-9]\}/', $pattern) !== 1) {
            return false;
        }
        $rest = (string) preg_replace(self::TOKEN, '', $pattern);

        return preg_match('/^[A-Za-z0-9\-_\/.]*$/', $rest) === 1;
    }

    public const SCHOOL_CODE = '/^[A-Z0-9]{1,10}$/';

    public static function usesSchool(string $pattern): bool
    {
        return str_contains($pattern, '{ECOLE}');
    }

    public static function format(string $pattern, string $establishmentCode, int $year, string $letter, int $counter, ?string $schoolCode = null): string
    {
        return (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($establishmentCode, $year, $letter, $counter, $schoolCode): string {
            return match (true) {
                $m[1] === 'CODE' => $establishmentCode,
                $m[1] === 'AA' => substr((string) $year, -2),
                $m[1] === 'AAAA' => (string) $year,
                $m[1] === 'LETTRE' => $letter,
                $m[1] === 'ECOLE' => (string) $schoolCode,
                default => str_pad((string) $counter, (int) substr($m[1], 1), '0', STR_PAD_LEFT),
            };
        }, $pattern);
    }

    /** Code d'établissement : initiales des mots du nom (« Collège La Palmeraie » donne CLP), ou 3 lettres pour un mot seul. */
    public static function establishmentCode(string $name): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $ascii = (string) preg_replace('/[^A-Za-z ]+/', '', $ascii);
        $words = array_values(array_filter(explode(' ', $ascii), static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            return 'ETB';
        }
        if (count($words) === 1) {
            return strtoupper(substr($words[0], 0, 3));
        }

        return strtoupper(implode('', array_map(static fn (string $w): string => $w[0], array_slice($words, 0, 4))));
    }
}
