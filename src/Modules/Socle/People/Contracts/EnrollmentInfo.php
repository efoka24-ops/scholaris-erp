<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Inscription d'une personne dans un établissement pour une année. */
final class EnrollmentInfo
{
    public const ACTIVE = 'active';
    /** Inscrit sous condition (pièces du dossier manquantes) : scolarisé et facturé comme un inscrit actif. */
    public const CONDITIONAL = 'conditional';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $personId,
        public readonly string $establishmentId,
        public readonly ?string $structureId,
        public readonly string $academicYearId,
        /** active, conditional (sous réserve), suspended, withdrawn, excluded, transferred, graduated */
        public readonly string $status,
        public readonly ?string $internalNumber = null,
    ) {
    }
}
