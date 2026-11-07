<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

/**
 * Port vers les inscriptions pour RG-PRE-2 : l'exclusion définitive prononcée par le conseil de discipline (PV signé) clôt l'inscription
 * au statut « exclu ». Le contrat d'écriture manque à Higher\Enrolment (demandé dans le rapport du lot I2) : tant qu'il n'est pas
 * branché, la liaison par défaut (PendingEnrolmentExclusion) ne modifie rien et la décision reste « exclusion à appliquer ».
 */
interface EnrolmentExclusion
{
    /** @return bool vrai si l'inscription est passée au statut « exclu » */
    public function exclude(string $tenantId, string $establishmentId, string $enrolmentId, string $reason, string $councilId, ?string $actorId): bool;
}
