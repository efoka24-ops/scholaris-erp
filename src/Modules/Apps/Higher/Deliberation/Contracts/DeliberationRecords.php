<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Contracts;

/**
 * Lecture des délibérations de l'IPES pour les autres sous-modules Higher (Documents, NationalExams, Tutelage, Portals…).
 * Borné au client et à l'établissement ; aucun contrôle de périmètre.
 */
interface DeliberationRecords
{
    /** PV de la délibération signé par le président (RG-DEL-3, RG-DOC-3) pour ce groupe, cette période et cette session. */
    public function minutesSigned(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session = 'normal'): bool;

    /**
     * Décision de fin d'année enregistrée après PV signé (RG-INS-3), null s'il n'y en a pas.
     *
     * @return array{decision_code: string, decision_label: string, credits_earned: int, cumulative_credits: int, diploma_file: bool, mention: ?string}|null
     */
    public function yearDecision(string $tenantId, string $establishmentId, string $personId, string $programId, string $academicYearId): ?array;
}
