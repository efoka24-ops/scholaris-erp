<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Contracts;

/**
 * Lecture des séances du cahier de textes pour les autres sous-modules Higher (Vacations : état mensuel, RG-ENS-1). Borné au client et à
 * l'établissement ; aucun contrôle de périmètre (à faire par l'appelant).
 */
interface TaughtSessions
{
    /**
     * Séances d'un enseignant tenues pendant le mois (tous statuts), triées par date.
     *
     * @param string $month AAAA-MM
     * @return list<TaughtSessionInfo>
     */
    public function ofTeacherInMonth(string $tenantId, string $establishmentId, string $personId, string $month): array;

    /**
     * Enseignants ayant au moins une séance validée pendant le mois, par département.
     *
     * @return list<array{person_id: string, school_id: string, department_id: string}>
     */
    public function teachersWithValidatedHours(string $tenantId, string $establishmentId, string $month): array;
}
