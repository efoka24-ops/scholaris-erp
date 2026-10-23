<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Catalogue\Contracts;

/** Une série (général) ou spécialité (technique) telle qu'un établissement la voit : libellé effectif, masquage effectif. */
final class StreamInfo
{
    public function __construct(
        public readonly string $id,
        /** platform (liste de référence) ou establishment (ajout propre à l'établissement) */
        public readonly string $scope,
        public readonly string $sectionCode,
        public readonly string $code,
        /** first ou second */
        public readonly string $cycleCode,
        /** series ou specialty */
        public readonly string $streamKind,
        /** general ou technical */
        public readonly string $teachingKind,
        /** GEN, STT ou IND */
        public readonly string $trackCode,
        public readonly string $label,
        public readonly bool $hidden,
        /** to_confirm (amorçage MINESEC), confirmed, custom (ajout avec source) */
        public readonly string $confirmation,
    ) {
    }
}
