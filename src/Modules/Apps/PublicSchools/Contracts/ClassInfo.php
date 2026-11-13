<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/** Classe (ou section de maternelle) d'une école publique : structure du socle enrichie du profil de classe (CLA). */
final class ClassInfo
{
    public const SHIFT_FULL_DAY = 'full_day';
    public const SHIFT_MORNING = 'morning';
    public const SHIFT_AFTERNOON = 'afternoon';

    /** @param list<string> $levelCodes niveaux de la classe ; plusieurs niveaux = classe jumelée */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** identifiant de la structure du socle (Organisation) */
        public readonly string $structureId,
        public readonly string $label,
        public readonly array $levelCodes,
        /** full_day, morning ou afternoon (double flux) */
        public readonly string $shift,
        /** personnel titulaire de la classe ; null si la classe n'a pas d'enseignant (RG-PRE-2) */
        public readonly ?string $homeroomStaffId,
    ) {
    }
}
