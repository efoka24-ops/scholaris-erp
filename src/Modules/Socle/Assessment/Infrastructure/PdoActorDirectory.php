<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Infrastructure;

use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/** Implémentation par défaut : passe par le contrat Identity. */
final class PdoActorDirectory implements ActorDirectory
{
    public function __construct(private readonly UserPersonLink $links)
    {
    }

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        return $this->links->personIdForUser($tenantId, $userId);
    }
}
