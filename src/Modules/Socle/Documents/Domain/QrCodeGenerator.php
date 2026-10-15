<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

/** Point d'extension : produit le code QR (balisage HTML/SVG sûr) de l'URL de vérification. */
interface QrCodeGenerator
{
    public function markup(string $verificationUrl): string;
}
