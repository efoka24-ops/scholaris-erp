<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Billing;

use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;

/** Doublure du lien compte utilisateur -> personne. */
final class PersonLinks implements UserPersonLink
{
    /** @var array<string, string> */
    private array $links = [];

    public function link(string $tenantId, string $userId, string $personId): void
    {
        $this->links[$tenantId . ':' . $userId] = $personId;
    }

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->links[$tenantId . ':' . $userId] ?? null;
    }
}
