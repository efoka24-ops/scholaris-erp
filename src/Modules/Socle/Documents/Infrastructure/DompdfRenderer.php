<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;

/**
 * Rendu PDF réel avec Dompdf, sans accès réseau : ressources distantes et PHP intégré désactivés,
 * polices embarquées (DejaVu Sans, couvrant les accents), accès fichiers limité au dossier de cache.
 */
final class DompdfRenderer implements PdfRenderer
{
    public function __construct(private readonly string $cachePath, private readonly string $paper = 'A4')
    {
    }

    public function render(string $html): string
    {
        if (!is_dir($this->cachePath) && !@mkdir($this->cachePath, 0775, true) && !is_dir($this->cachePath)) {
            throw new RuntimeException('Dossier de cache PDF inaccessible.');
        }

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([$this->cachePath]);
        $options->setFontCache($this->cachePath);
        $options->setTempDir($this->cachePath);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize($this->paper);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $output = $dompdf->output();
        if (!str_starts_with($output, '%PDF')) {
            throw new RuntimeException('Le rendu PDF a échoué.');
        }

        return $output;
    }
}
