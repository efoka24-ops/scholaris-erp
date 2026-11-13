<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Infrastructure;

use Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts\NationalIds;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\StudentFacts;

/**
 * Faits d'élève fournis par Enrolment aux documents et portails (`secondary.student_facts.enrolment`) : matricule national, lu à sa
 * source unique (CarteScolaire, contrat NationalIds) ; null = « en attente ».
 */
final class EnrolmentStudentFacts implements StudentFacts
{
    public function __construct(private readonly NationalIds $nationalIds)
    {
    }

    public function nationalId(string $tenantId, string $establishmentId, string $personId): ?string
    {
        return $this->nationalIds->of($tenantId, $establishmentId, $personId);
    }
}
