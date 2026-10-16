<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Contracts;

/**
 * Lecture des notes de soutenance pour les autres sous-modules Higher (Deliberation, Portals, Dashboards). Borné au client et à
 * l'établissement ; aucun contrôle de périmètre (à faire par l'appelant). Seules les soutenances clôturées (PV établi) comptent.
 */
interface DefenseResults
{
    /** @return list<array{defense_id: string, internship_id: string, person_id: string, academic_year_id: string, program_id: string, level_id: string, grade: float, held_on: string}> */
    public function closedFor(string $tenantId, string $establishmentId, string $personId): array;
}
