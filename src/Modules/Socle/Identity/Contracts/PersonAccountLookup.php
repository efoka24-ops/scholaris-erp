<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/** Sens inverse de UserPersonLink : une personne possède-t-elle un compte utilisateur dans ce client ? */
interface PersonAccountLookup
{
    public function hasAccount(string $tenantId, string $personId): bool;
}
