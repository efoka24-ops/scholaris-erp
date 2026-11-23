<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/** Établissement tel que les autres modules le voient (lecture seule). */
final class EstablishmentInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $name,
        /** nursery, primary, secondary ou higher : détermine l'application qui le gère */
        public readonly string $type,
        /** couleur d'accent (#RRGGBB), contraste 4,5:1 avec du texte noir garanti */
        public readonly string $accent,
        public readonly ?string $logoPath = null,
        /** type d'établissement du secondaire (leg, ces, cetic...), null ailleurs */
        public readonly ?string $subtype = null,
    ) {
    }
}
