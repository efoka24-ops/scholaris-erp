<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Résultat d'un export fichier vers un organisme externe. */
final class ExportReport
{
    /** @param list<string> $columns en-têtes écrits, dans l'ordre */
    public function __construct(
        public readonly string $path,
        public readonly string $format,
        public readonly int $rows,
        public readonly array $columns,
    ) {
    }
}
