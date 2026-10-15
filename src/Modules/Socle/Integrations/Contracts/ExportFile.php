<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Fichier d'export produit en mémoire (S14) : contenu, type MIME, nom proposé, colonnes et nombre de lignes. */
final class ExportFile
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly string $content,
        public readonly string $format,
        public readonly string $contentType,
        public readonly string $filename,
        public readonly int $rows,
        public readonly array $columns,
    ) {
    }
}
