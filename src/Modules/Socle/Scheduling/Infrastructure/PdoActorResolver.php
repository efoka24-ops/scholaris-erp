<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/** Personne derrière un compte utilisateur, par le contrat Identity. */
final class PdoActorResolver
{
    public function __construct(private readonly UserPersonLink $links)
    {
    }

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        return $this->links->personIdForUser($tenantId, $userId);
    }
}
