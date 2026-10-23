<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\TileIndicators;

/** Repli : aucune application ne fournit d'indicateurs, les tuiles n'en portent pas. */
final class NoTileIndicators implements TileIndicators
{
    public function forTile(string $tenantId, string $establishmentId, array $roleCodes): array
    {
        return [];
    }
}
