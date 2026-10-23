<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Scholaris\Modules\Socle\Documents\Domain\QrCodeGenerator;

/**
 * Code QR réel (chillerlan/php-qrcode, PHP pur, sans GD). Le balisage est une image SVG en data URI : aucune
 * ressource distante, et lisible aussi bien dans un navigateur que par Dompdf.
 */
final class LibraryQrCodeGenerator implements QrCodeGenerator
{
    private const QUIET_ZONE = 2;

    public function markup(string $verificationUrl): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::M,
            'addQuietzone' => false,
        ]);
        $qr = new QRCode($options);
        $qr->addByteSegment($verificationUrl);
        $matrix = $qr->getQRMatrix();
        $size = $matrix->getSize();
        $total = $size + 2 * self::QUIET_ZONE;

        $cells = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->check($x, $y)) {
                    $cells .= '<rect x="' . ($x + self::QUIET_ZONE) . '" y="' . ($y + self::QUIET_ZONE) . '" width="1" height="1"/>';
                }
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . ($total * 4) . '" height="' . ($total * 4) . '" viewBox="0 0 ' . $total . ' ' . $total . '">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/><g fill="#000000">' . $cells . '</g></svg>';
        $url = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<figure class="qr"><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="96" height="96" alt="Code QR de vérification">'
            . '<figcaption>' . $url . '</figcaption></figure>';
    }
}
