<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Port d'analyse antivirus des pièces téléversées (à brancher plus tard ; l'implémentation par défaut refuse les contenus actifs). */
interface FileScanner
{
    public function isClean(string $content, string $mime): bool;
}
