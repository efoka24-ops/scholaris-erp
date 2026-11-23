<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\RoleDirectory;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectionDirectory;

/**
 * Direction d'un établissement d'après les rôles : directeur et directeur adjoint de cet établissement (ou de niveau client).
 * La direction de groupe n'en fait pas partie : elle n'est pas l'interlocutrice des parents d'un établissement.
 */
final class RoleBasedDirectionDirectory implements DirectionDirectory
{
    public const ROLES = ['directeur', 'directeur_adjoint'];

    public function __construct(private readonly RoleDirectory $roles)
    {
    }

    public function directionPersonIds(string $tenantId, string $establishmentId): array
    {
        return $this->roles->personIdsWithRole($tenantId, $establishmentId, self::ROLES);
    }
}
