<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

use DateTimeInterface;

/**
 * Exécution des gardes-fous d'inscription enregistrés par les applications (D3), dans l'ordre des noms d'entrée. Fourni par
 * Organisation (PdoEnrolmentGate), consommé par People\EnrollmentService::validate. Sans garde enregistré : null, sans lecture.
 */
interface EnrolmentGuards
{
    /** Préfixe des entrées de liste du conteneur : `organisation.enrolment.guards.<application>` => [classe ou instance, …]. */
    public const PREFIX = 'organisation.enrolment.guards.';

    /** @return string|null premier motif de refus, null si aucun garde ne s'y oppose */
    public function refusal(
        string $tenantId,
        string $establishmentId,
        string $structureId,
        ?string $academicYearId,
        ?string $personId,
        DateTimeInterface $on,
        ?string $enrollmentId = null,
    ): ?string;
}
