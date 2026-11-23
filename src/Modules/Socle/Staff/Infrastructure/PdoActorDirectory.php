<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\PersonAccountLookup;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/** Personne derrière un compte utilisateur et existence d'un compte, par les contrats Identity. */
final class PdoActorDirectory
{
    public function __construct(private readonly UserPersonLink $links, private readonly PersonAccountLookup $accounts)
    {
    }

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        return $this->links->personIdForUser($tenantId, $userId);
    }

    public function hasAccount(string $tenantId, string $personId): bool
    {
        return $this->accounts->hasAccount($tenantId, $personId);
    }
}
