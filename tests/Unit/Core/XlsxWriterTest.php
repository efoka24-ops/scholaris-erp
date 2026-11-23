<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\ExportsList;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\XlsxWriter;
use Tests\Support\Exports\ReadsXlsx;

/** Écrivain XLSX : structure du paquet, types de cellules, accents, neutralisation des formules, choix du format. */
final class XlsxWriterTest extends TestCase
{
    use ReadsXlsx;

    private function binary(): string
    {
        return (new XlsxWriter())->build(
            ['Numéro', 'Titulaire', 'Montant', 'Date', 'Note'],
            [
                ['CLP-1', 'Zoé & "Ngo" <b>', 12500, '2026-10-09', null],
                ['CLP-2', '=1+1', -3, '2026-02-30', "ligne1\nligne2"],
            ],
            'Registre',
        );
    }

    public function testPaquetOuvrableAvecLesPartiesObligatoires(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tst');
        file_put_contents((string) $file, $this->binary());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open((string) $file));
        $types = (string) $zip->getFromName('[Content_Types].xml');
        self::assertStringContainsString('spreadsheetml.sheet.main+xml', $types);
        self::assertStringContainsString('/xl/worksheets/sheet1.xml', $types);
        self::assertStringContainsString('name="Registre"', (string) $zip->getFromName('xl/workbook.xml'));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('<c r="A1" s="1" t="inlineStr">', $sheet, 'En-têtes en gras.');
        self::assertStringContainsString('<cols>', $sheet);
        self::assertStringNotContainsString('<f>', $sheet, 'Aucune formule.');
        $zip->close();
        @unlink((string) $file);
    }

    public function testLignesAccentsEtTypes(): void
    {
        $rows = self::sheetRows($this->binary());

        self::assertCount(3, $rows);
        self::assertSame(['Numéro', 'Titulaire', 'Montant', 'Date', 'Note'], $rows[0]);
        self::assertSame('Zoé & "Ngo" <b>', $rows[1][1]);
        self::assertSame('12500', $rows[1][2], 'Nombre stocké comme nombre.');
        self::assertSame('46304', $rows[1][3], 'Date stockée comme numéro de série (9 octobre 2026).');
        self::assertCount(4, $rows[1], 'Une cellule vide n\'est pas écrite.');
        self::assertSame('-3', $rows[2][2]);
        self::assertSame('2026-02-30', $rows[2][3], 'Une date impossible reste du texte.');
    }

    public function testUneCelluleQuiCommenceParEgalEstNeutralisee(): void
    {
        $rows = self::sheetRows($this->binary());

        self::assertSame("'=1+1", $rows[2][1]);
    }

    public function testCaracteresDeControleRetires(): void
    {
        $rows = self::sheetRows((new XlsxWriter())->build(['a'], [["x\x01y\x0Bz"]]));

        self::assertSame('xyz', $rows[1][0]);
    }

    public function testBeaucoupDeLignesEtNomsDeColonnesAuDelaDeZ(): void
    {
        $headers = array_map(static fn (int $i): string => 'c' . $i, range(1, 30));
        $generator = (static function (): \Generator {
            for ($i = 1; $i <= 2000; $i++) {
                yield array_fill(0, 30, (string) $i);
            }
        })();
        $rows = self::sheetRows((new XlsxWriter())->build($headers, $generator));

        self::assertCount(2001, $rows);
        self::assertCount(30, $rows[2000]);
    }

    public function testChoixDuFormatParLaRequete(): void
    {
        $exporter = new class () {
            use ExportsList;

            /** @param list<list<mixed>> $rows */
            public function respond(ServerRequestInterface $request, array $rows): ResponseInterface
            {
                $factory = new Psr17Factory();

                return $this->csvResponse($factory, $factory, 'liste élèves.csv', ['n'], $rows, $request);
            }
        };

        $csv = $exporter->respond(new ServerRequest('GET', '/x'), [['1']]);
        self::assertSame('text/csv; charset=utf-8', $csv->getHeaderLine('Content-Type'));

        $explicit = $exporter->respond((new ServerRequest('GET', '/x'))->withQueryParams(['format' => 'CSV']), [['1']]);
        self::assertSame('text/csv; charset=utf-8', $explicit->getHeaderLine('Content-Type'));

        $xlsx = $exporter->respond((new ServerRequest('GET', '/x'))->withQueryParams(['format' => 'xlsx']), [['1']]);
        self::assertSame(XlsxWriter::CONTENT_TYPE, $xlsx->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="liste_l_ves.xlsx"', $xlsx->getHeaderLine('Content-Disposition'));
        self::assertSame([['n'], ['1']], self::sheetRows((string) $xlsx->getBody()));

        $this->expectException(HttpException::class);
        $exporter->respond((new ServerRequest('GET', '/x'))->withQueryParams(['format' => 'pdf']), [['1']]);
    }
}
