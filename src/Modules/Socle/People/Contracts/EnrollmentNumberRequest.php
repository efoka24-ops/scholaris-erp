<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Inscription à numéroter (D5). */
final class EnrollmentNumberRequest
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        /** nursery, primary, secondary ou higher ; vide si l'établissement est introuvable */
        public readonly string $establishmentType,
        public readonly string $enrollmentId,
        public readonly string $personId,
        public readonly string $structureId,
        public readonly string $academicYearId,
        /** année civile de la validation (AAAA), celle du numéro du socle */
        public readonly int $year,
    ) {
    }
}
