<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Logo d'un établissement, déjà contrôlé et redimensionné (PNG ou JPEG, 300 px de large au plus). */
final class LogoImage
{
    public function __construct(
        public readonly string $mime,
        public readonly string $content,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /** Contenu en data URI (image intégrée à un document : le document émis reste inchangé si le logo est remplacé ensuite). */
    public function dataUri(): string
    {
        return 'data:' . $this->mime . ';base64,' . base64_encode($this->content);
    }
}
