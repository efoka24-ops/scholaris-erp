<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Infrastructure;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Secondary\Migration\Domain\MigrationDefaults;

/**
 * Lecture d'un fichier de reprise (même lecteur que l'import des élèves et la reprise du supérieur) : CSV (UTF-8, séparateur « ; »)
 * ou XLSX (première feuille). En-têtes normalisés (minuscules, sans accents, espaces en « _ ») ; colonnes obligatoires du jeu
 * exigées ; colonnes inconnues signalées, jamais utilisées. Lignes rendues par numéro de ligne du fichier (en-tête = ligne 1).
 */
final class SheetReader
{
    /**
     * @param list<string> $columns
     * @param list<string> $required
     * @return array{rows: array<int, array<string, string>>, unknown: list<string>}
     */
    public function read(string $format, string $content, array $columns, array $required): array
    {
        if ($content === '' || strlen($content) > MigrationDefaults::MAX_BYTES) {
            throw HttpException::validation(['file' => ['Fichier vide ou trop volumineux (8 Mo au plus) : découpez-le en plusieurs lots.']]);
        }
        $table = match ($format) {
            'csv' => $this->csv($content),
            'xlsx' => $this->xlsx($content),
            default => throw HttpException::validation(['format' => ['Format attendu : csv ou xlsx.']]),
        };
        if ($table === []) {
            throw HttpException::validation(['file' => ['Le fichier ne contient aucune ligne.']]);
        }
        $first = array_key_first($table);
        $headers = array_map(self::normalize(...), $table[$first]);
        unset($table[$first]);
        $missing = array_values(array_diff($required, $headers));
        if ($missing !== []) {
            throw HttpException::validation(['file' => ['Colonne(s) obligatoire(s) absente(s) : ' . implode(', ', $missing) . '. Téléchargez le modèle de fichier.']]);
        }
        $unknown = array_values(array_filter(array_unique($headers), static fn (string $h): bool => $h !== '' && !in_array($h, $columns, true)));
        $rows = [];
        foreach ($table as $line => $cells) {
            $row = array_fill_keys($columns, '');
            foreach ($headers as $index => $header) {
                if (in_array($header, $columns, true)) {
                    $row[$header] = self::cell((string) ($cells[$index] ?? ''));
                }
            }
            if (implode('', $row) === '') {
                continue;
            }
            if (count($rows) >= MigrationDefaults::MAX_ROWS) {
                throw HttpException::validation(['file' => ['Plus de ' . MigrationDefaults::MAX_ROWS . ' lignes : découpez le fichier en plusieurs lots.']]);
            }
            $rows[$line] = $row;
        }
        if ($rows === []) {
            throw HttpException::validation(['file' => ['Le fichier ne contient aucune ligne de données.']]);
        }

        return ['rows' => $rows, 'unknown' => $unknown];
    }

    /** Cellule nettoyée : l'apostrophe de protection d'une formule (« '+237… », exports de la plateforme et d'Excel) est retirée. */
    private static function cell(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\'[=+\-@]/', $value) === 1 ? substr($value, 1) : $value;
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }

    /** @return array<int, list<string>> */
    private function csv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw HttpException::validation(['file' => ['Le fichier doit être encodé en UTF-8 (Excel : « CSV UTF-8 »).']]);
        }
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Lecture impossible.');
        }
        fwrite($handle, $content);
        rewind($handle);
        $table = [];
        $line = 0;
        while (($cells = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            ++$line;
            if ($cells === [null]) {
                continue;
            }
            $table[$line] = array_map(static fn (?string $c): string => (string) $c, $cells);
        }
        fclose($handle);

        return $table;
    }

    /** @return array<int, list<string>> */
    private function xlsx(string $content): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpException(501, 'Format indisponible', 'Le format Excel n\'est pas disponible sur ce serveur : utilisez un fichier CSV.');
        }
        $path = tempnam(sys_get_temp_dir(), 'mig');
        if ($path === false) {
            throw new \RuntimeException('Fichier temporaire impossible.');
        }
        try {
            file_put_contents($path, $content);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw HttpException::validation(['file' => ['Fichier Excel illisible : enregistrez-le au format .xlsx ou en CSV.']]);
            }
            try {
                $strings = [];
                $shared = $zip->getFromName('xl/sharedStrings.xml');
                if (is_string($shared) && ($xml = simplexml_load_string($shared)) !== false) {
                    foreach ($xml->si as $si) {
                        $text = '';
                        foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $text .= (string) $t;
                        }
                        $strings[] = $text;
                    }
                }
                $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
                if (!is_string($sheetXml)) {
                    throw HttpException::validation(['file' => ['Première feuille introuvable dans le classeur.']]);
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw HttpException::validation(['file' => ['Feuille Excel illisible.']]);
        }
        $table = [];
        $auto = 0;
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $number = isset($row['r']) ? (int) $row['r'] : ++$auto;
            $cells = [];
            foreach ($row->c as $cell) {
                $index = self::columnIndex((string) $cell['r']);
                $type = (string) $cell['t'];
                $value = (string) $cell->v;
                if ($type === 's') {
                    $value = $strings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) $cell->is->t;
                }
                $cells[$index] = $value;
            }
            if ($cells === []) {
                continue;
            }
            $list = [];
            for ($i = 0; $i <= max(array_keys($cells)); ++$i) {
                $list[] = $cells[$i] ?? '';
            }
            $table[$number] = $list;
        }

        return $table;
    }

    private static function columnIndex(string $reference): int
    {
        $letters = (string) preg_replace('/\d+/', '', $reference);
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return max(0, $index - 1);
    }
}
