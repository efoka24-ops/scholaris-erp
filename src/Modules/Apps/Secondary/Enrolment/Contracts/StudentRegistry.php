<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Contracts;

/**
 * Dossiers secondaires des élèves (contrat interne de Secondary, fourni par Enrolment) : Discipline (exclusion définitive
 * prononcée par un conseil de discipline, RG-PRE-3), Councils et OfficialExams (redoublement).
 */
interface StudentRegistry
{
    public function profile(string $tenantId, string $establishmentId, string $personId): ?StudentProfileInfo;

    /** Exclusion définitive décidée par un conseil de discipline : la réinscription exigera une décision motivée (RG-INS-3). */
    public function markFinalExclusion(string $tenantId, string $establishmentId, string $personId, string $date, string $reference): void;
}
