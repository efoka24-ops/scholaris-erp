<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\FileScanner;

/** Analyse par défaut en attendant un antivirus : refuse les PDF à contenu actif et les images contenant du code. */
final class HeuristicFileScanner implements FileScanner
{
    private const PDF_ACTIVE = ['/JavaScript', '/Launch', '/EmbeddedFile', '/RichMedia'];
    private const CODE = ['<?php', '<script', '<?='];

    public function isClean(string $content, string $mime): bool
    {
        $markers = $mime === 'application/pdf' ? self::PDF_ACTIVE : self::CODE;
        foreach ($markers as $marker) {
            if (stripos($content, $marker) !== false) {
                return false;
            }
        }

        return true;
    }
}
