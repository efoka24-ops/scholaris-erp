<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Contrat sortant de Staff pour le portail enseignant : classes affectées et heures réalisées d'une personne. */
interface TeacherWorkload
{
    /**
     * Affectations actives d'une personne, tous établissements du client confondus.
     *
     * @return list<array{establishment_id: string, structure_id: ?string, subject_label: ?string, session_kind: string}>
     */
    public function assignmentsOf(string $tenantId, string $personId): array;

    /**
     * Heures d'une personne sur un mois dans un établissement, par statut.
     *
     * @param string $month « AAAA-MM »
     * @return array{month: string, declared_minutes: int, validated_minutes: int, rejected_minutes: int}
     */
    public function hoursOf(string $tenantId, string $establishmentId, string $personId, string $month): array;
}
