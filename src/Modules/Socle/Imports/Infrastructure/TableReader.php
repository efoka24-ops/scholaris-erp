<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Imports\Infrastructure;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Imports\Domain\PeopleColumns;

/**
 * Lecture d'un fichier d'acteurs : CSV (UTF-8 ou Windows-1252, séparateur « ; », « , » ou tabulation) ou XLSX (première feuille).
 * XLSX sans dépendance : ZipArchive + SimpleXML (chaînes partagées et en ligne ; les dates Excel restent des numéros de série,
 * convertis plus loin pour les seules colonnes de date). Le format se reconnaît au CONTENU, jamais au nom du fichier.
 * Rend les lignes par numéro de ligne du fichier (l'en-tête est la ligne 1), colonnes sous leur clé interne ; une ligne
 * entièrement vide est ignorée. Le contenu n'est jamais écrit ailleurs qu'en fichier temporaire supprimé aussitôt (XLSX).
 */
final class TableReader
{
    /** Taille maximale d'une partie décompressée du classeur (protection contre les archives piégées). */
    private const MAX_PART_BYTES = 31457280;
    private const MAX_CELLS_PER_ROW = 60;

    /**
     * @return array{format: string, rows: array<int, array<string, string>>, unknown: list<string>}
     */
    public function read(string $content): array
    {
        if ($content === '') {
            throw HttpException::validation(['file' => ['Le fichier est vide.']]);
        }
        if (strlen($content) > PeopleColumns::MAX_BYTES) {
            throw HttpException::validation(['file' => ['Fichier trop volumineux (2 Mo au plus) : découpez-le en plusieurs fichiers.']]);
        }
        if (str_starts_with($content, "\xD0\xCF\x11\xE0")) {
            throw HttpException::validation(['file' => ['Ancien format Excel (.xls) non pris en charge : enregistrez le classeur en .xlsx ou en CSV UTF-8.']]);
        }
        $isXlsx = str_starts_with($content, "PK\x03\x04");
        $table = $isXlsx ? $this->xlsx($content) : $this->csv($content);
        if ($table === []) {
            throw HttpException::validation(['file' => ['Le fichier ne contient aucune ligne.']]);
        }

        $headerLine = array_key_first($table);
        $keys = [];
        $unknown = [];
        foreach ($table[$headerLine] as $index => $header) {
            $key = PeopleColumns::keyOf($header);
            if ($key === null) {
                if (trim($header) !== '') {
                    $unknown[] = mb_substr(trim($header), 0, 60);
                }
            } elseif (!in_array($key, $keys, true)) {
                $keys[$index] = $key;
            }
        }
        unset($table[$headerLine]);
        $missing = array_values(array_diff(PeopleColumns::REQUIRED, $keys));
        if ($missing !== []) {
            $labels = array_map(static fn (string $key): string => PeopleColumns::HEADERS[$key], $missing);
            throw HttpException::validation(['file' => ['Colonne(s) obligatoire(s) absente(s) : ' . implode(', ', $labels) . '. Téléchargez le modèle de fichier et conservez ses en-têtes.']]);
        }

        $rows = [];
        foreach ($table as $line => $cells) {
            $row = array_fill_keys(array_keys(PeopleColumns::HEADERS), '');
            foreach ($keys as $index => $key) {
                $row[$key] = trim($cells[$index] ?? '');
            }
            if (implode('', $row) === '') {
                continue;
            }
            if (count($rows) >= PeopleColumns::MAX_LINES) {
                throw HttpException::validation(['file' => ['Plus de ' . PeopleColumns::MAX_LINES . ' lignes : découpez le fichier en plusieurs lots.']]);
            }
            $rows[$line] = $row;
        }
        if ($rows === []) {
            throw HttpException::validation(['file' => ['Le fichier ne contient aucune personne (seulement l\'en-tête).']]);
        }

        return ['format' => $isXlsx ? 'xlsx' : 'csv', 'rows' => $rows, 'unknown' => array_values(array_unique($unknown))];
    }

    /** @return array<int, array<int, string>> */
    private function csv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (str_contains($content, "\0")) {
            throw HttpException::validation(['file' => ['Fichier illisible : envoyez un CSV (UTF-8) ou un classeur .xlsx.']]);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Excel en français enregistre parfois en Windows-1252 : conversion plutôt que refus.
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstLine = (string) strtok($content, "\n");
        $separator = ';';
        $best = substr_count($firstLine, ';');
        foreach ([',', "\t"] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $best) {
                $separator = $candidate;
                $best = $count;
            }
        }
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Lecture impossible.');
        }
        fwrite($handle, $content);
        rewind($handle);
        $table = [];
        $line = 0;
        while (($cells = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            ++$line;
            if ($cells === [null]) {
                continue;
            }
            $table[$line] = array_map(static fn (?string $cell): string => (string) $cell, array_slice($cells, 0, self::MAX_CELLS_PER_ROW));
        }
        fclose($handle);

        return $table;
    }

    /** @return array<int, array<int, string>> */
    private function xlsx(string $content): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw HttpException::validation(['file' => ['Les classeurs Excel (.xlsx) ne sont pas lus par ce serveur (extension zip absente) : enregistrez le fichier en CSV UTF-8 (séparateur « ; » ou « , ») et envoyez-le à nouveau.']]);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp');
        if ($path === false) {
            throw new \RuntimeException('Fichier temporaire impossible.');
        }
        try {
            file_put_contents($path, $content);
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw HttpException::validation(['file' => ['Classeur Excel illisible : enregistrez-le au format .xlsx ou en CSV UTF-8.']]);
            }
            try {
                $sheetXml = $this->part($zip, $this->firstSheetPath($zip))
                    ?? throw HttpException::validation(['file' => ['Première feuille introuvable dans le classeur.']]);
                $sharedXml = $this->part($zip, 'xl/sharedStrings.xml');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        $strings = [];
        if ($sharedXml !== null) {
            foreach ($this->xml($sharedXml)->si as $si) {
                $strings[] = self::richText($si);
            }
        }
        $sheet = $this->xml($sheetXml);
        $table = [];
        $auto = 0;
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $number = isset($row['r']) ? (int) $row['r'] : ++$auto;
            $cells = [];
            foreach ($row->c as $cell) {
                $index = self::columnIndex((string) $cell['r']);
                if ($index >= self::MAX_CELLS_PER_ROW) {
                    continue;
                }
                $type = (string) $cell['t'];
                $value = (string) $cell->v;
                if ($type === 's') {
                    $value = $strings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = self::richText($cell->is);
                } elseif ($type === 'b') {
                    $value = $value === '1' ? '1' : '0';
                } elseif ($type !== 'str' && $type !== 'e' && preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $value) === 1) {
                    // Grand nombre stocké en notation scientifique (téléphone saisi sans apostrophe) : retour à l'entier.
                    $value = sprintf('%.0f', (float) $value);
                }
                $cells[$index] = $value;
            }
            if ($cells === []) {
                continue;
            }
            $list = [];
            for ($i = 0; $i <= max(array_keys($cells)); ++$i) {
                $list[$i] = $cells[$i] ?? '';
            }
            $table[$number] = $list;
        }

        return $table;
    }

    /** Chemin de la première feuille selon le classeur ; à défaut `xl/worksheets/sheet1.xml`. */
    private function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = $this->part($zip, 'xl/workbook.xml');
        $rels = $this->part($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook !== null && $rels !== null) {
            $book = $this->xml($workbook);
            $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $first = $book->xpath('//m:sheets/m:sheet[1]');
            $relId = is_array($first) && isset($first[0])
                ? (string) $first[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id
                : '';
            if ($relId !== '') {
                foreach ($this->xml($rels)->Relationship as $relationship) {
                    if ((string) $relationship['Id'] === $relId) {
                        $target = ltrim((string) $relationship['Target'], '/');

                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function part(\ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw HttpException::validation(['file' => ['Classeur trop volumineux une fois décompressé : utilisez un CSV.']]);
        }
        $data = $zip->getFromName($name);

        return is_string($data) ? $data : null;
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        // Pas de DTD ni d'entités : un classeur légitime n'en contient jamais.
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw HttpException::validation(['file' => ['Classeur Excel refusé (contenu non conforme).']]);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $parsed instanceof \SimpleXMLElement ? $parsed : throw HttpException::validation(['file' => ['Feuille Excel illisible : enregistrez-la en CSV UTF-8.']]);
    }

    /** Texte d'une chaîne partagée ou en ligne : texte direct + segments de texte riche, sans les annotations phonétiques. */
    private static function richText(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        $text = (string) $node->t;
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
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
