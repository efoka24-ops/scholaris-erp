<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/** Règlement des études applicable (EX-ACA-05, RG-ACA-3), pour la délibération, les inscriptions et les campagnes. */
interface RegulationBook
{
    public function effective(string $tenantId, string $establishmentId, string $schoolId, string $regime, string $academicYearId): ?RegulationInfo;

    /**
     * Fige le règlement à l'ouverture des inscriptions (RG-ACA-3) ; sans effet s'il l'est déjà. Journalisé.
     *
     * @return RegulationInfo|null null si aucun règlement n'est enregistré pour cette école, ce régime et cette année
     */
    public function freezeForEnrolment(string $tenantId, string $establishmentId, string $schoolId, string $regime, string $academicYearId, ?string $actorId = null): ?RegulationInfo;
}
