<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Application;

use Scholaris\Core\Http\HttpException;

/**
 * Fichier CSV de la feuille de compétences (C110-PR2) : une ligne par élève, une colonne par compétence du niveau (en-tête = code
 * de la compétence), cellule = code de l'échelle ou d'absence de l'école, vide = rien. Séparateur « ; » ou « , », UTF-8 (avec ou
 * sans BOM) ou Windows-1252, comme l'import des notes du socle.
 */
final class CompetencySheetCsv
{
    private const KEY_HEADERS = ['identifiant', 'person_id', 'id'];
    private const NAME_HEADERS = ['nom', 'name'];

    /**
     * @param list<string> $competencyCodes
     * @param list<array{person_id: string, name: string}> $learners
     */
    public static function template(array $competencyCodes, array $learners): string
    {
        $clean = static fn (string $v): string => str_replace([';', "\r", "\n"], ' ', $v);
        $out = "\xEF\xBB\xBF" . implode(';', ['identifiant', 'nom', ...array_map($clean, $competencyCodes)]) . "\r\n";
        foreach ($learners as $learner) {
            $out .= $clean($learner['person_id']) . ';' . $clean($learner['name']) . str_repeat(';', count($competencyCodes)) . "\r\n";
        }

        return $out;
    }

    /**
     * @return array{columns: array<int, string>, rows: list<array{line: int, key: string, cells: array<int, string>}>}
     *                                             colonnes des compétences (index => en-tête tel quel), lignes non vides
     */
    public static function parse(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $numbered = [];
        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $numbered[$index + 1] = $line;
            }
        }
        if (count($numbered) < 2) {
            throw HttpException::validation(['file' => ['Le fichier doit contenir une ligne d\'en-tête puis au moins une ligne d\'élève.']]);
        }
        $headerLine = (string) reset($numbered);
        $delimiter = substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
        $header = array_map(static fn (?string $h): string => trim((string) $h), str_getcsv($headerLine, $delimiter, '"', ''));
        $key = null;
        $columns = [];
        foreach ($header as $index => $name) {
            $lower = mb_strtolower($name);
            if ($key === null && in_array($lower, self::KEY_HEADERS, true)) {
                $key = $index;
            } elseif (!in_array($lower, self::NAME_HEADERS, true) && $name !== '') {
                $columns[$index] = $name;
            }
        }
        if ($key === null || $columns === []) {
            throw HttpException::validation(['file' => ['En-tête attendu : identifiant, nom (facultatif), puis le code de chaque compétence. Téléchargez le modèle.']]);
        }

        $rows = [];
        foreach (array_slice($numbered, 1, null, true) as $lineNo => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $values = [];
            foreach (array_keys($columns) as $index) {
                $values[$index] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[] = ['line' => $lineNo, 'key' => trim((string) ($cells[$key] ?? '')), 'cells' => $values];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
