<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Contracts;

/**
 * Enregistrement des décisions de passage de l'année N (DEL, vague 3 ; reprise de données) qui conditionnent la réinscription
 * (RG-INS-3) et le contrat pédagogique de N+1 (RG-INS-4 : UE acquises capitalisées, UE manquantes en dette). Idempotent : une
 * nouvelle décision pour la même personne, filière et année remplace la précédente (réouverture de délibération, EX-DEL-08).
 */
interface ProgressionDecisionRecorder
{
    /**
     * @param string $decision admitted, admitted_with_debts, repeat ou excluded
     * @param list<string> $acquiredUnitCodes codes des UE acquises (validation, compensation, capitalisation)
     * @param list<string> $missingUnitCodes codes des UE non acquises (dettes)
     */
    public function record(
        string $tenantId,
        string $establishmentId,
        string $personId,
        string $programId,
        string $levelId,
        string $academicYearId,
        string $decision,
        array $acquiredUnitCodes,
        array $missingUnitCodes,
        ?string $actorId,
    ): void;
}
