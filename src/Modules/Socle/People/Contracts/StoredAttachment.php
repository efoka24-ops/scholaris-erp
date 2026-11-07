<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Pièce enregistrée par AttachmentStore : chemin relatif opaque (à conserver), type réel, extension et taille en octets. */
final class StoredAttachment
{
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly string $extension,
        public readonly int $size,
    ) {
    }
}
