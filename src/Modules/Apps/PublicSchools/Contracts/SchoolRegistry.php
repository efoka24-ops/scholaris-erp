<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/**
 * Registre de l'école publique et du groupe scolaire (ECO), fourni par PublicSchools\Schools. Lecture seule : la fiche, l'acte de
 * création, les infrastructures annuelles et les photos de visite restent propres à PublicSchools. Toutes les méthodes sont
 * bornées au client (`tenantId`) : une école d'un autre client est introuvable (null ou liste vide).
 */
interface SchoolRegistry
{
    /** Fiche de l'école liée à l'établissement, ou null si l'établissement n'a pas de fiche dans ce client. */
    public function find(string $tenantId, string $establishmentId): ?SchoolInfo;

    /**
     * Écoles du même groupe scolaire que l'établissement (lui-même compris), triées par code officiel ; une école seule rend
     * sa propre fiche ; liste vide si l'établissement est inconnu.
     *
     * @return list<SchoolInfo>
     */
    public function groupOf(string $tenantId, string $establishmentId): array;

    /** Vrai si l'école existe et que son statut est « open » (une école fermée ou suspendue n'accueille aucune inscription). */
    public function isOpen(string $tenantId, string $establishmentId): bool;
}
