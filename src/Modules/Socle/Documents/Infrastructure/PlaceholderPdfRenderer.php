<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;

/**
 * Rendu de repli hors production (aucune bibliothèque sollicitée), de même sortie que le simulateur du module
 * Integrations dont un module ne peut pas utiliser l'infrastructure. Les tests qui veulent le simulateur officiel
 * le substituent dans le conteneur.
 */
final class PlaceholderPdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        return "%PDF-1.4\n%SIMULATED\n" . strip_tags($html) . "\n%%EOF\n";
    }
}
