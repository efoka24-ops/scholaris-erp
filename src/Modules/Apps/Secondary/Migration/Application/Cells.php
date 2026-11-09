<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Migration\Infrastructure\SheetReader;

/** Lecture des cellules d'un fichier de reprise : dates (ISO, JJ/MM/AAAA, numéro de série Excel), entiers, clés comparables. */
final class Cells
{
    private function __construct()
    {
    }

    public static function date(string $value): ?string
    {
        $value = trim($value);
        $iso = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($iso !== false && $iso->format('Y-m-d') === $value) {
            return $value;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (ctype_digit($value) && (int) $value > 0) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $value . ' days')->format('Y-m-d');
        }

        return null;
    }

    /** Entier positif en XAF (espaces, points et espaces insécables de milliers tolérés), sinon null. */
    public static function amount(string $value): ?int
    {
        $digits = str_replace([' ', "\u{00A0}", "\u{202F}", '.'], '', trim($value));

        return ctype_digit($digits) ? (int) $digits : null;
    }

    public static function fold(string $value): string
    {
        return SheetReader::normalize($value);
    }

    public static function isExample(string ...$values): bool
    {
        foreach ($values as $value) {
            if (str_starts_with(strtoupper(trim($value)), 'EXEMPLE')) {
                return true;
            }
        }

        return false;
    }

    /** Message lisible d'un refus : première erreur de champ, sinon le détail. */
    public static function explain(HttpException $e): string
    {
        foreach ($e->errors as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return $e->getMessage();
    }
}
