<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/** Annuaire des personnes qui exercent un rôle dans un établissement. Fourni par Identity ; les autres modules ne lisent jamais `role_assignments`. */
interface RoleDirectory
{
    /**
     * @param list<string> $roles codes de rôle (ex. « directeur »)
     * @return list<string> identifiants des personnes ayant, à cet instant, une affectation en vigueur de l'un de ces rôles qui s'exerce
     *                      dans cet établissement (affectation de cet établissement ou de niveau client)
     */
    public function personIdsWithRole(string $tenantId, string $establishmentId, array $roles): array;
}
