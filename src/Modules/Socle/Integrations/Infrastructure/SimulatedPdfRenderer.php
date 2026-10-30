<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Infrastructure;

use Scholaris\Modules\Socle\Integrations\Contracts\PdfRenderer;

/** Simulateur : retourne un faux PDF reconnaissable. Le rendu réel (DomPDF) arrive avec la phase 8. */
final class SimulatedPdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        return "%PDF-1.4\n%SIMULATED\n" . strip_tags($html) . "\n%%EOF\n";
    }
}
