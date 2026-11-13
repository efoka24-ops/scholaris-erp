<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Membre du personnel d'un établissement, vu par EstablishmentStaffRoster (enseignant ou non). */
final class StaffMemberInfo
{
    public function __construct(
        /** identifiant de la fiche du personnel (staff_members.id) */
        public readonly string $staffId,
        public readonly string $personId,
        public readonly string $tenantId,
        /** statut d'emploi du socle : permanent, vacataire ou invite */
        public readonly string $kind,
        /** active (seuls les membres actifs sont listés) */
        public readonly string $status,
        /** fonction ou grade (« Professeur certifié », « Secrétaire », « Agent d'entretien »…) */
        public readonly ?string $grade,
        /** catégorie libre de la fiche du personnel */
        public readonly ?string $category,
        /** vrai si le membre a une affectation d'enseignement active dans l'établissement */
        public readonly bool $teaches,
    ) {
    }
}
