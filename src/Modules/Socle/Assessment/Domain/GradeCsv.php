<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Domain;

use Scholaris\Core\Http\HttpException;

/**
 * Lecture d'un import de notes au format CSV (à défaut de bibliothèque Excel) : séparateur « ; » ou « , »,
 * UTF-8 ou Windows-1252, virgule ou point décimal. Colonnes : identifiant (matricule ou person_id), nom (facultatif), note.
 * Dans la colonne note : un nombre, « ABS » ou « ABJ » (absent justifié), « ABI » (absent non justifié), ou vide (rien).
 */
final class GradeCsv
{
    private const IDENTIFIER_HEADERS = ['matricule', 'person_id', 'identifiant', 'id'];
    private const VALUE_HEADERS = ['note', 'value', 'valeur'];

    /** @return list<array{line: int, key: string, raw: string}> */
    public static function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        if (count($lines) < 2) {
            throw HttpException::validation(['file' => ['Le fichier doit contenir une ligne d\'en-tête puis au moins une ligne de notes.']]);
        }

        $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn (?string $h): string => mb_strtolower(trim((string) $h)), str_getcsv($lines[0], $delimiter, '"', ''));
        $keyColumn = self::column($header, self::IDENTIFIER_HEADERS);
        $valueColumn = self::column($header, self::VALUE_HEADERS);
        if ($keyColumn === null || $valueColumn === null) {
            throw HttpException::validation(['file' => ['En-tête attendu : matricule (ou person_id), nom (facultatif), note. Téléchargez le modèle.']]);
        }

        $rows = [];
        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $rows[] = [
                'line' => $index + 1,
                'key' => trim((string) ($cells[$keyColumn] ?? '')),
                'raw' => trim((string) ($cells[$valueColumn] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Modèle à télécharger : une ligne par élève, colonne note vide (UTF-8 avec BOM pour Excel).
     *
     * @param list<array{key: string, name: string}> $students
     */
    public static function template(array $students): string
    {
        $out = "\xEF\xBB\xBF" . "matricule;nom;note\r\n";
        foreach ($students as $student) {
            $out .= str_replace([';', "\r", "\n"], ' ', $student['key']) . ';' . str_replace([';', "\r", "\n"], ' ', $student['name']) . ";\r\n";
        }

        return $out;
    }

    /**
     * @param list<string> $header
     * @param list<string> $names
     */
    private static function column(array $header, array $names): ?int
    {
        foreach ($header as $index => $name) {
            if (in_array(ltrim($name, "\xEF\xBB\xBF"), $names, true)) {
                return $index;
            }
        }

        return null;
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $converted = function_exists('iconv') ? iconv('Windows-1252', 'UTF-8//IGNORE', $content) : false;

        return $converted === false ? mb_convert_encoding($content, 'UTF-8', 'Windows-1252') : $converted;
    }
}
