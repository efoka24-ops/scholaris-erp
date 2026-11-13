<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

/**
 * Profil PDF « léger » (PP15, RG-DOC-6, SC-08 : PDF parent < 200 Ko) : polices de base non embarquées (Helvetica), aucune
 * police externe (@font-face, <link>), images intégrées de plus de MAX_IMAGE_BYTES retirées (texte alternatif conservé).
 * Le code QR et les petits cachets restent. Transformation pure du HTML, appliquée juste avant le rendu.
 */
final class LightPdfProfile
{
    public const STANDARD = 'standard';
    public const LIGHT = 'light';
    public const PROFILES = [self::STANDARD, self::LIGHT];
    /** Taille maximale (octets encodés) d'une image intégrée conservée. */
    public const MAX_IMAGE_BYTES = 24000;
    public const STYLE = '<style>@page{margin:1.5cm}body{font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:1.4;color:#000}'
        . 'h1{text-align:center;font-size:16px;border-bottom:1px solid #000;padding-bottom:4px}h2{text-align:center;font-size:13px}'
        . '.qr{margin:8px 0}.qr figcaption{font-size:8px}.duplicata,.rectificatif{text-align:center}.page-break{page-break-after:always}</style>';

    private function __construct()
    {
    }

    public static function apply(string $html): string
    {
        $html = (string) preg_replace('#<link\b[^>]*>#i', '', $html);
        $html = (string) preg_replace('#@font-face\s*\{[^}]*\}#i', '', $html);
        $font = 'font-family:Helvetica,sans-serif';
        // Feuilles de style : la déclaration s'arrête à « ; » ou « } ». Attributs style : aussi aux guillemets doubles.
        $html = (string) preg_replace_callback(
            '#<style\b[^>]*>.*?</style>#is',
            static fn (array $m): string => (string) preg_replace('#font-family\s*:[^;}<]*#i', $font, $m[0]),
            $html,
        );
        $html = (string) preg_replace('#font-family\s*:(?:\'[^\'<>]*\'|[^;}"\'<>])*#i', $font, $html);

        return (string) preg_replace_callback(
            '#<img\b[^>]*\bsrc\s*=\s*(["\'])(data:[^"\']*)\1[^>]*>#i',
            static function (array $m): string {
                if (strlen($m[2]) <= self::MAX_IMAGE_BYTES) {
                    return $m[0];
                }
                $alt = preg_match('#\balt\s*=\s*(["\'])([^"\']*)\1#i', $m[0], $a) === 1 ? $a[2] : '';

                return $alt === '' ? '' : '<span class="img-alt">' . $alt . '</span>';
            },
            $html,
        );
    }
}
