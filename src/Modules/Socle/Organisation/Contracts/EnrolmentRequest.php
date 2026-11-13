<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

use DateTimeImmutable;

/** Inscription soumise aux gardes-fous (D3) : structure visée, niveau et filière déduits de la hiérarchie, année, personne. */
final class EnrolmentRequest
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** nursery, primary, secondary ou higher */
        public readonly string $establishmentType,
        public readonly string $structureId,
        public readonly ?string $academicYearId,
        public readonly ?string $personId,
        public readonly DateTimeImmutable $on,
        /** structure de niveau : la structure elle-même ou son ascendante de nature `level` */
        public readonly ?string $levelId = null,
        /** filière : la structure elle-même ou son ascendante de nature `track` */
        public readonly ?string $trackId = null,
        public readonly ?string $enrollmentId = null,
    ) {
    }
}
