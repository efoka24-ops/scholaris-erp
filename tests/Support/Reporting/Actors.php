<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;

/** Doublure d'ActorDirectory : utilisateur => personne. */
final class Actors implements ActorDirectory
{
    /** @var array<string, string> */
    private array $map = [];

    public function link(string $tenantId, string $userId, string $personId): void
    {
        $this->map[$tenantId . '|' . $userId] = $personId;
    }

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        return $this->map[$tenantId . '|' . $userId] ?? null;
    }
}
