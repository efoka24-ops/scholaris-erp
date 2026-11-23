<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Infrastructure;

use Scholaris\Modules\Apps\Higher\Attendance\Application\EnrolmentExclusion;

/** Liaison par défaut tant que Higher\Enrolment n'expose pas de contrat de changement de statut : l'exclusion reste à appliquer. */
final class PendingEnrolmentExclusion implements EnrolmentExclusion
{
    public function exclude(string $tenantId, string $establishmentId, string $enrolmentId, string $reason, string $councilId, ?string $actorId): bool
    {
        return false;
    }
}
