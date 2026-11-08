<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/**
 * Contrat sortant de Staff : tout le personnel actif d'un établissement (enseignant, administratif, service, entretien…), rattaché à
 * l'établissement ou affecté à lui, avec sa fonction (grade), sa catégorie et son statut d'emploi. Lecture seule, cloisonnée par client
 * et établissement : un membre d'un autre client ou d'un autre établissement n'apparaît jamais.
 */
interface EstablishmentStaffRoster
{
    /** @return list<StaffMemberInfo> membres actifs, par identifiant de personne */
    public function membersOf(string $tenantId, string $establishmentId): array;
}
