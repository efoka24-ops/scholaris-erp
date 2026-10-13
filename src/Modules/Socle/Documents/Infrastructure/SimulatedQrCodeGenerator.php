<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;

/** Simulateur : un cadre « QR » portant l'URL. Un générateur réel (bibliothèque) remplacera cette liaison. */
final class SimulatedQrCodeGenerator implements QrCodeGenerator
{
    public function markup(string $verificationUrl): string
    {
        $url = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<figure class="qr" data-simulated="1"><svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" role="img" aria-label="QR">'
            . '<title>' . $url . '</title><rect width="96" height="96" fill="#fff" stroke="#000"/><text x="48" y="52" text-anchor="middle" font-size="14">QR</text></svg>'
            . '<figcaption>' . $url . '</figcaption></figure>';
    }
}
