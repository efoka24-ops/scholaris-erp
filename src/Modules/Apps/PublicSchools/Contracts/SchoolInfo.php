<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/** Fiche minimale d'une école publique (ECO) exposée aux autres applications : aucune donnée de visite ni d'infrastructure. */
final class SchoolInfo
{
    public const CYCLE_NURSERY = 'nursery';
    public const CYCLE_PRIMARY = 'primary';
    public const CYCLE_GROUP = 'group';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_SUSPENDED = 'suspended';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** code officiel de l'école, tel que saisi (aucune garantie de validation nationale) */
        public readonly string $officialCode,
        /** francophone, anglophone ou bilingual */
        public readonly string $subsystem,
        /** nursery, primary ou group (groupe scolaire) */
        public readonly string $cycle,
        /** même valeur pour la maternelle et le primaire d'un même groupe scolaire ; null si l'école est seule */
        public readonly ?string $schoolGroupRef,
        /** open, closed ou suspended */
        public readonly string $status,
    ) {
    }
}
