<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Identity\Application\ContextService;
use Scholaris\Modules\Socle\Identity\Contracts\AccessibleEstablishments;

/** Établissements du lanceur de l'utilisateur (rôles en vigueur), sans la tuile « Tout le groupe ». */
final class UserEstablishments implements AccessibleEstablishments
{
    public function __construct(
        private readonly PdoUserRepository $users,
        private readonly ContextService $contexts,
    ) {
    }

    public function idsFor(string $tenantId, string $userId): array
    {
        $user = $this->users->find($tenantId, $userId);
        if ($user === null || !$user->isActive()) {
            return [];
        }
        $ids = [];
        foreach ($this->contexts->contexts($user, 'fr') as $tile) {
            if ($tile['kind'] === 'establishment') {
                $ids[] = $tile['id'];
            }
        }

        return $ids;
    }
}
