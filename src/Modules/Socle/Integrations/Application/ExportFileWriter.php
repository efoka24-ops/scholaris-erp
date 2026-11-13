<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Application;

use InvalidArgumentException;
use RuntimeException;
use Scholaris\Core\Http\XlsxWriter;
use Scholaris\Modules\Socle\Integrations\Contracts\ExportReport;

/**
 * Écrit des lignes dans un fichier CSV (UTF-8, séparateur configurable, cellules neutralisées contre l'injection de formule),
 * JSON ou XLSX (XlsxWriter du noyau). Les colonnes sont une correspondance « en-tête de sortie => champ source » venue de la configuration.
 */
final class ExportFileWriter
{
    /**
     * @param array<string, string> $columns en-tête de sortie => champ source
     * @param list<array<string, scalar|null>> $records enregistrements disponibles (champs source)
     * @param list<string> $allowedFields champs source autorisés
     */
    public function write(string $path, string $format, string $delimiter, array $columns, array $records, array $allowedFields): ExportReport
    {
        [$content, $format, $rows] = $this->render($format, $delimiter, $columns, $records, $allowedFields);
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Écriture impossible : ' . $path);
        }

        return new ExportReport($path, $format, $rows, array_map('strval', array_keys($columns)));
    }

    /**
     * Contenu du fichier sans l'écrire (S14) : [contenu, format normalisé, nombre de lignes].
     *
     * @param array<string, string> $columns en-tête de sortie => champ source
     * @param list<array<string, scalar|null>> $records
     * @param list<string> $allowedFields
     * @return array{0: string, 1: string, 2: int}
     */
    public function render(string $format, string $delimiter, array $columns, array $records, array $allowedFields, string $sheetName = 'Export'): array
    {
        if ($columns === []) {
            throw new InvalidArgumentException('Aucune colonne configurée pour cet export.');
        }
        foreach ($columns as $header => $field) {
            if (!in_array($field, $allowedFields, true)) {
                throw new InvalidArgumentException(sprintf('Champ source inconnu « %s » pour la colonne « %s ». Champs disponibles : %s.', $field, $header, implode(', ', $allowedFields)));
            }
        }
        $format = strtolower($format);
        $lines = array_map(static function (array $record) use ($columns): array {
            $line = [];
            foreach ($columns as $header => $field) {
                $line[(string) $header] = $record[$field] ?? null;
            }

            return $line;
        }, $records);

        if ($format === 'json') {
            $content = json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } elseif ($format === 'csv') {
            $content = $this->csv(array_map('strval', array_keys($columns)), $lines, $delimiter === '' ? ';' : $delimiter[0]);
        } elseif ($format === 'xlsx') {
            $content = (new XlsxWriter())->build(array_map('strval', array_keys($columns)), array_map('array_values', $lines), $sheetName);
        } else {
            throw new InvalidArgumentException('Format inconnu : ' . $format . ' (csv, json ou xlsx).');
        }

        return [(string) $content, $format, count($lines)];
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, scalar|null>> $lines
     */
    private function csv(array $headers, array $lines, string $delimiter): string
    {
        $handle = fopen('php://temp/maxmemory:4194304', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Export impossible.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_map(self::cell(...), $headers), $delimiter);
        foreach ($lines as $line) {
            fputcsv($handle, array_map(self::cell(...), array_values($line)), $delimiter);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
    }
}
