<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Infrastructure;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Migration\Domain\ImportKind;

/**
 * Lecture d'un fichier de reprise : CSV (UTF-8, séparateur « ; ») ou XLSX (première feuille). Les en-têtes sont normalisés (minuscules,
 * sans accents, espaces en « _ »). Les colonnes obligatoires doivent exister ; les colonnes inconnues sont signalées, jamais utilisées.
 * Rend les lignes par numéro de ligne du fichier (l'en-tête est la ligne 1).
 */
final class TableReader
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @return array{rows: array<int, array<string, string>>, unknown: list<string>}
     */
    public function read(string $kind, string $format, string $content): array
    {
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
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
        $missing = array_values(array_diff(ImportKind::required($kind), $headers));
        if ($missing !== []) {
            throw HttpException::validation(['file' => ['Colonne(s) obligatoire(s) absente(s) : ' . implode(', ', $missing) . '. Téléchargez le modèle de fichier.']]);
        }
        $known = ImportKind::columns($kind);
        $unknown = array_values(array_filter(array_unique($headers), static fn (string $h): bool => $h !== '' && !in_array($h, $known, true)));
        $rows = [];
        foreach ($table as $line => $cells) {
            if (count($rows) >= ImportKind::MAX_ROWS) {
                throw HttpException::validation(['file' => ['Plus de ' . ImportKind::MAX_ROWS . ' lignes : découpez le fichier en plusieurs lots.']]);
            }
            $row = array_fill_keys($known, '');
            foreach ($headers as $index => $header) {
                if (in_array($header, $known, true)) {
                    $row[$header] = trim((string) ($cells[$index] ?? ''));
                }
            }
            if (implode('', $row) !== '') {
                $rows[$line] = $row;
            }
        }

        return ['rows' => $rows, 'unknown' => $unknown];
    }

    public static function normalize(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
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
        $path = tempnam(sys_get_temp_dir(), 'imp');
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
