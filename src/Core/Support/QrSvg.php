<?php

declare(strict_types=1);

namespace Scholaris\Core\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Code QR en image SVG (data URI), sans ressource distante ni GD : sert à l'enrôlement de la double authentification
 * (lien otpauth:// lu par l'application d'authentification).
 */
final class QrSvg
{
    private const QUIET_ZONE = 4;

    private function __construct()
    {
    }

    public static function dataUri(string $content): string
    {
        $qr = new QRCode(new QROptions(['eccLevel' => EccLevel::M, 'addQuietzone' => false]));
        $qr->addByteSegment($content);
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/><g fill="#000000">' . $cells . '</g></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
