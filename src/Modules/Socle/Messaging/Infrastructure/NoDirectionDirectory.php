<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;

/** Refus par défaut : personne n'est reconnu comme direction tant qu'un module ne fournit pas l'annuaire. */
final class NoDirectionDirectory implements DirectionDirectory
{
    public function directionPersonIds(string $tenantId, string $establishmentId): array
    {
        return [];
    }
}
