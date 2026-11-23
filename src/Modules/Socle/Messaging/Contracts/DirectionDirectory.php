<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Personnes de la direction d'un établissement (directeur, adjoints), interlocutrices des parents dans la messagerie.
 * À fournir par Identity ou Staff (liaison dans leur definitions.php) ; tant qu'il n'est pas lié, aucune
 * conversation « direction-parent » n'est possible.
 */
interface DirectionDirectory
{
    /** @return list<string> identifiants des personnes de la direction de cet établissement */
    public function directionPersonIds(string $tenantId, string $establishmentId): array;
}
