<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Scholaris\Core\Http\ExportsList;

final class ExportsListTest extends TestCase
{
    private object $exporter;

    protected function setUp(): void
    {
        $this->exporter = new class () {
            use ExportsList;

            /**
             * @param list<string> $headers
             * @param iterable<list<mixed>> $rows
             */
            public function body(array $headers, iterable $rows): string
            {
                return $this->csvBody($headers, $rows);
            }

            /**
             * @param list<string> $headers
             * @param iterable<list<mixed>> $rows
             */
            public function response(string $filename, array $headers, iterable $rows): ResponseInterface
            {
                $factory = new Psr17Factory();

                return $this->csvResponse($factory, $factory, $filename, $headers, $rows);
            }
        };
    }

    public function testBomSeparateurPointVirguleEtAccents(): void
    {
        $csv = $this->exporter->body(['Numéro', 'Titulaire'], [['CLP-26-A-000001', 'Zoé; "Ngo"']]);

        self::assertStringStartsWith("\xEF\xBB\xBFNuméro;Titulaire\n", $csv);
        self::assertStringContainsString("CLP-26-A-000001;\"Zoé; \"\"Ngo\"\"\"\n", $csv);
    }

    public function testNeutraliseLesFormulesMaisPasLesNombres(): void
    {
        $csv = $this->exporter->body(['a', 'b', 'c', 'd', 'e'], [['=1+1', '@SUM(A1)', -5, null, true]]);

        self::assertStringContainsString("'=1+1;'@SUM(A1);-5;;1\n", $csv);
    }

    public function testAccepteUnGenerateurDeLignes(): void
    {
        $rows = (static function (): \Generator {
            yield ['1'];
            yield ['2'];
        })();

        self::assertStringEndsWith("1\n2\n", $this->exporter->body(['n'], $rows));
    }

    public function testReponseDeTelechargement(): void
    {
        $response = $this->exporter->response('liste élèves/2026.csv', ['n'], [['1']]);

        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="liste_l_ves_2026.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringEndsWith("1\n", (string) $response->getBody());
    }
}
