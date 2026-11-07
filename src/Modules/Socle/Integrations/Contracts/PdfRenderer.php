<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Conversion HTML vers PDF (DomPDF en recette, Gotenberg en production). */
interface PdfRenderer
{
    /** @return string contenu binaire du PDF */
    public function render(string $html): string;
}
