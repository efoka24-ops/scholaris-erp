<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application;

/** Lecture tolérante des cellules d'un fichier de reprise : dates, entiers, oui/non, texte normalisé. */
final class Cells
{
    private function __construct()
    {
    }

    /** Date AAAA-MM-JJ, JJ/MM/AAAA ou numéro de série Excel ; null si illisible. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $value) === 1 && (float) $value > 20000 && (float) $value < 80000) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
        }

        return null;
    }

    /** Entier positif (espaces et séparateurs de milliers tolérés) ; null si illisible. */
    public static function int(string $value): ?int
    {
        $clean = (string) preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $value);
        $clean = str_replace(',', '.', $clean);
        if (preg_match('/^\d+(\.0+)?$/', $clean) !== 1) {
            return null;
        }

        return (int) $clean;
    }

    public static function decimal(string $value): ?float
    {
        $clean = str_replace(',', '.', trim($value));

        return preg_match('/^\d+(\.\d+)?$/', $clean) === 1 ? (float) $clean : null;
    }

    public static function yes(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['1', 'oui', 'o', 'yes', 'y', 'true', 'vrai'], true);
    }

    /** Clé de comparaison d'un nom : minuscules sans accents ni ponctuation. */
    public static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    public static function mode(string $value): ?string
    {
        return match (self::fold($value)) {
            '', 'jour', 'day' => 'day',
            'soir', 'evening' => 'evening',
            'alternance', 'work study', 'work_study' => 'work_study',
            default => null,
        };
    }
}
