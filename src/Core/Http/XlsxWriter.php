<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

use DateTimeImmutable;

/**
 * Écrivain XLSX minimal (une feuille, PHP pur sur ZipArchive) : en-têtes en gras et figés, cellules texte / nombre /
 * date, largeurs de colonnes bornées, aucune formule. Les lignes sont écrites une à une dans un fichier temporaire.
 */
final class XlsxWriter
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MAX_WIDTH = 60;
    private const MIN_WIDTH = 8;
    /** Excel plafonne une cellule à 32 767 caractères. */
    private const MAX_CELL = 32767;

    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Contenu binaire du classeur.
     *
     * @param list<string> $headers
     * @param iterable<list<mixed>> $rows
     */
    public function build(array $headers, iterable $rows, string $sheetName = 'Export'): string
    {
        if (!self::available()) {
            throw new HttpException(501, 'Format indisponible', "Le format Excel n'est pas disponible sur ce serveur (extension zip manquante) ; utilisez format=csv.");
        }

        $body = $this->tempFile();
        $sheet = $this->tempFile();
        $archive = $this->tempFile();

        try {
            $widths = array_map(static fn (string $h): int => mb_strlen($h) + 2, $headers);
            $bodyHandle = $this->open($body);
            $this->writeRow($bodyHandle, 1, $headers, $widths, true);
            $count = 1;
            foreach ($rows as $row) {
                $count++;
                $this->writeRow($bodyHandle, $count, $row, $widths, false);
            }
            fclose($bodyHandle);

            $sheetHandle = $this->open($sheet);
            fwrite($sheetHandle, $this->sheetHead($widths, $count));
            $in = $this->open($body, 'rb');
            stream_copy_to_stream($in, $sheetHandle);
            fclose($in);
            fwrite($sheetHandle, '</sheetData></worksheet>');
            fclose($sheetHandle);

            // On laisse ZipArchive créer lui-même le fichier : sous Windows, renommer son fichier temporaire par-dessus
            // un fichier déjà créé par tempnam() échoue par intermittence (« Permission denied »).
            @unlink($archive);
            $zip = new \ZipArchive();
            if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Export impossible.');
            }
            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rootRels());
            $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
            $zip->addFromString('xl/styles.xml', self::styles());
            $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
            if (!$zip->close()) {
                throw new \RuntimeException('Export impossible.');
            }

            return (string) file_get_contents($archive);
        } finally {
            foreach ([$body, $sheet, $archive] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('Export impossible.');
        }

        return $path;
    }

    /** @return resource */
    private function open(string $path, string $mode = 'wb')
    {
        $handle = fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException('Export impossible.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @param list<mixed> $cells
     * @param array<int, int> $widths
     */
    private function writeRow($handle, int $rowNumber, array $cells, array &$widths, bool $header): void
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach ($cells as $index => $value) {
            $ref = self::column($index) . $rowNumber;
            $cell = $this->cell($ref, $value, $header);
            if ($cell === '') {
                continue;
            }
            $xml .= $cell;
            $length = self::displayLength($value);
            if (!$header && $length + 2 > ($widths[$index] ?? 0)) {
                $widths[$index] = $length + 2;
            }
        }
        fwrite($handle, $xml . '</row>');
    }

    private function cell(string $ref, mixed $value, bool $header): string
    {
        if ($header) {
            return '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . self::escape((string) (is_scalar($value) ? $value : '')) . '</t></is></c>';
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return '<c r="' . $ref . '"><v>' . ($value ? '1' : '0') . '</v></c>';
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return '<c r="' . $ref . '"><v>' . $value . '</v></c>';
        }
        $text = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $serial = (int) floor((new DateTimeImmutable($text . ' 00:00:00 UTC'))->getTimestamp() / 86400) + 25569;

            return '<c r="' . $ref . '" s="2"><v>' . $serial . '</v></c>';
        }
        if (in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }

        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::escape($text) . '</t></is></c>';
    }

    private static function displayLength(mixed $value): int
    {
        $text = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        $longest = 0;
        foreach (explode("\n", $text) as $line) {
            $longest = max($longest, mb_strlen($line));
        }

        return $longest;
    }

    /** @param array<int, int> $widths */
    private function sheetHead(array $widths, int $rowCount): string
    {
        $cols = '';
        foreach ($widths as $index => $width) {
            $n = $index + 1;
            $w = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width));
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
        }
        $last = self::column(max(0, count($widths) - 1)) . max(1, $rowCount);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $last . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>';
    }

    /** Nom de colonne Excel (0 = A, 26 = AA). */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $name = chr(65 + $n % 26) . $name;
        }

        return $name;
    }

    private static function escape(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        if (mb_strlen($text) > self::MAX_CELL) {
            $text = mb_substr($text, 0, self::MAX_CELL);
        }

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function sanitizeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/?*\[\]:]+/', ' ', $name));

        return mb_substr($clean !== '' ? $clean : 'Export', 0, 31);
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape(self::sanitizeName($sheetName)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
