<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

use DateTimeInterface;

/**
 * Garde IPES des campagnes et inscriptions (RG-ACA-1, RG-ACA-2), la même que celle enregistrée auprès du socle sous
 * `organisation.enrolment.guards.higher` : autorisation MINESUP valide pour le niveau à la date, et, pour une filière LMD,
 * université de tutelle enregistrée pour l'année. À appeler par Admissions avant d'ouvrir une campagne ou d'admettre.
 */
interface EnrolmentEligibility
{
    /**
     * @param string $structureId niveau ou groupe visé (une filière seule est refusée : le niveau est exigé)
     * @return string|null motif du refus, null si l'inscription est permise
     */
    public function refusalFor(string $tenantId, string $establishmentId, string $structureId, ?string $academicYearId, DateTimeInterface $on): ?string;
}
