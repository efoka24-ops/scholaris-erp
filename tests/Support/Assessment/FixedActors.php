<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;

/** Doublure d'ActorDirectory : utilisateur => personne. */
final class FixedActors implements ActorDirectory
{
    /** @var array<string, string> */
    public array $persons = [];

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        return $this->persons[$userId] ?? null;
    }
}
