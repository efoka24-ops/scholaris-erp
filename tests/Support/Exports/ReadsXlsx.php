<?php

declare(strict_types=1);

namespace Tests\Support\Exports;

/** Relecture d'un classeur .xlsx produit par l'application (ZipArchive + SimpleXML), pour les tests d'export. */
trait ReadsXlsx
{
    /**
     * @param array<string, string> $headers
     * @return list<list<string>> lignes de la feuille 1 (texte brut des cellules ; cellules vides absentes décalent les colonnes)
     */
    protected function xlsxRows(string $path, array $headers): array
    {
        $response = $this->call('GET', '/api/v1' . $path, $headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="[^"]+\.xlsx"$/', $response->getHeaderLine('Content-Disposition'));

        return self::sheetRows((string) $response->getBody());
    }

    /** @return list<list<string>> */
    protected static function sheetRows(string $binary): array
    {
        $file = tempnam(sys_get_temp_dir(), 'tst');
        self::assertNotFalse($file);
        file_put_contents($file, $binary);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($file));
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
                self::assertNotFalse($zip->locateName($part), $part . ' manquant');
            }
            $sheet = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
        } finally {
            @unlink($file);
        }
        self::assertNotFalse($sheet);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $cells[] = isset($cell->is) ? (string) $cell->is->t : (string) $cell->v;
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
