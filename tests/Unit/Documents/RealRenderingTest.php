<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Config\Config;
use Scholaris\Core\Container\ContainerFactory;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;
use Scholaris\Modules\Socle\Documents\Infrastructure\DompdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\LibraryQrCodeGenerator;
use Scholaris\Modules\Socle\Documents\Infrastructure\PlaceholderPdfRenderer;
use Scholaris\Modules\Socle\Documents\Infrastructure\SimulatedQrCodeGenerator;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;

/** Rendu réel (Dompdf) et code QR réel : aucun accès réseau. */
final class RealRenderingTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/scholaris-dompdf-test';
    }

    public function testDompdfProduitUnVraiPdf(): void
    {
        $pdf = (new DompdfRenderer($this->cache))->render('<html><body><h1>Collège La Palmeraie</h1><p>Attestation de scolarité — élève Nadia Fotso</p></body></html>');

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }

    public function testRessourceDistanteIgnoreeSansErreurNiAccesReseau(): void
    {
        $started = microtime(true);
        $pdf = (new DompdfRenderer($this->cache))->render('<html><body><img src="http://203.0.113.9/logo.png"><link rel="stylesheet" href="https://example.invalid/a.css"><p>Texte</p></body></html>');

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testLeCodeQrReelEstUneImageSvgIncorporeeEtSeRendDansLePdf(): void
    {
        $url = 'https://api.scholaris.test/api/v1/public/verify/abcDEF123';
        $markup = (new LibraryQrCodeGenerator())->markup($url);

        self::assertStringContainsString('src="data:image/svg+xml;base64,', $markup);
        self::assertStringContainsString($url, $markup);
        self::assertSame($markup, (new LibraryQrCodeGenerator())->markup($url), 'Déterministe pour une même URL.');
        self::assertNotSame(
            explode('"', explode('base64,', $markup)[1])[0],
            explode('"', explode('base64,', (new LibraryQrCodeGenerator())->markup($url . 'x'))[1])[0],
            'Le motif dépend de l\'URL.',
        );
        preg_match('/base64,([^"]+)"/', $markup, $m);
        $svg = (string) base64_decode($m[1], true);
        self::assertNotFalse(simplexml_load_string($svg));
        self::assertGreaterThan(100, substr_count($svg, '<rect'));

        $pdf = (new DompdfRenderer($this->cache))->render('<html><body><p>Vérification</p>' . $markup . '</body></html>');
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(strlen((new DompdfRenderer($this->cache))->render('<html><body><p>Vérification</p></body></html>')), strlen($pdf), 'Le QR est dessiné dans le PDF.');
    }

    public function testSelectionDesImplementationsParConfiguration(): void
    {
        $base = dirname(__DIR__, 3);
        $resolve = static function (array $documents, string $env) use ($base): array {
            $container = ContainerFactory::create($base, Config::fromArray(['app' => ['env' => $env], 'documents' => $documents]));

            return [$container->get(PdfRenderer::class), $container->get(QrCodeGenerator::class)];
        };

        [$pdf, $qr] = $resolve([], 'production');
        self::assertInstanceOf(DompdfRenderer::class, $pdf);
        self::assertInstanceOf(LibraryQrCodeGenerator::class, $qr);

        [$pdf, $qr] = $resolve([], 'local');
        self::assertInstanceOf(PlaceholderPdfRenderer::class, $pdf);
        self::assertInstanceOf(SimulatedQrCodeGenerator::class, $qr);

        [$pdf, $qr] = $resolve(['pdf_renderer' => 'simulated', 'qr_generator' => 'simulated'], 'production');
        self::assertInstanceOf(PlaceholderPdfRenderer::class, $pdf);
        self::assertInstanceOf(SimulatedQrCodeGenerator::class, $qr);

        [$pdf] = $resolve(['pdf_renderer' => 'dompdf'], 'local');
        self::assertInstanceOf(DompdfRenderer::class, $pdf);
    }
}
