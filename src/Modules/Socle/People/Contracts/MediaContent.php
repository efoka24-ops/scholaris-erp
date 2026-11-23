<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Contenu d'un média lu par MediaStore : octets, type réel, extension et taille. */
final class MediaContent
{
    public function __construct(
        public readonly string $content,
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $size,
    ) {
    }
}
