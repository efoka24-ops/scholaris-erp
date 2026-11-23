<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\CarteScolaireDefaults;
use Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain\IdentityKey;

/**
 * Lecture d'un fichier CSV UTF-8 (marque d'ordre des octets retirée, fins de ligne Windows ou Unix) dont les colonnes sont
 * nommées par paramètre : la première ligne porte les en-têtes, comparés sans accents ni casse. Une colonne obligatoire absente :
 * 422 avant toute écriture. Rend chaque ligne de données avec son numéro de ligne dans le fichier.
 */
final class CsvTable
{
    /**
     * @param array<string, string> $columns champ => en-tête
     * @param list<string> $required champs obligatoires
     * @return list<array{line: int, values: array<string, string>}>
     */
    public static function parse(string $content, string $delimiter, array $columns, array $required): array
    {
        if (strlen($content) > CarteScolaireDefaults::MAX_BYTES) {
            throw HttpException::validation(['content' => [sprintf('Fichier trop volumineux (%d octets au plus).', CarteScolaireDefaults::MAX_BYTES)]]);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw HttpException::validation(['content' => ['Le fichier doit être encodé en UTF-8.']]);
        }
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $header = null;
        $rows = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv($line, $delimiter));
            if ($header === null) {
                $header = self::header($cells, $columns, $required);
                continue;
            }
            $values = [];
            foreach ($header as $field => $position) {
                $values[$field] = $cells[$position] ?? '';
            }
            $rows[] = ['line' => $index + 1, 'values' => $values];
        }
        if ($header === null) {
            throw HttpException::validation(['content' => ['Fichier vide : la première ligne doit porter les en-têtes.']]);
        }

        return $rows;
    }

    /**
     * @param list<string> $cells
     * @param array<string, string> $columns
     * @param list<string> $required
     * @return array<string, int> champ => position
     */
    private static function header(array $cells, array $columns, array $required): array
    {
        $positions = [];
        foreach ($cells as $position => $cell) {
            $positions[IdentityKey::text($cell)] = $position;
        }
        $header = [];
        $missing = [];
        foreach ($columns as $field => $title) {
            $key = IdentityKey::text($title);
            if (array_key_exists($key, $positions)) {
                $header[$field] = $positions[$key];
            } elseif (in_array($field, $required, true)) {
                $missing[] = $title;
            }
        }
        if ($missing !== []) {
            throw HttpException::validation(['content' => ['Colonnes absentes : ' . implode(', ', $missing) . ' (paramètre secondary.carte_scolaire.columns.*).']]);
        }

        return $header;
    }

    /** Date du fichier (AAAA-MM-JJ ou JJ/MM/AAAA) au format AAAA-MM-JJ, ou null. */
    public static function date(string $value): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($value));
            if ($date !== false && $date->format(substr($format, 1)) === trim($value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** Montant entier en XAF (espaces et points de milliers tolérés), ou null. */
    public static function amount(string $value): ?int
    {
        $digits = (string) preg_replace('/[\s.\x{00A0}\x{202F}]/u', '', $value);

        return preg_match('/^\d{1,9}$/', $digits) === 1 ? (int) $digits : null;
    }
}
