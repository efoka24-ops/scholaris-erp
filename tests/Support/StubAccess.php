<?php

declare(strict_types=1);

namespace Tests\Support;

use Scholaris\Core\Security\EstablishmentAccess;
use Scholaris\Core\Security\Principal;

/** Doublure : droits explicites « utilisateur => établissements autorisés ». Refus par défaut. */
final class StubAccess implements EstablishmentAccess
{
    /** @var array<string, list<string>> */
    private array $grants = [];

    public function grant(string $userId, string $establishmentId): void
    {
        $this->grants[$userId][] = $establishmentId;
    }

    public function canAccess(Principal $principal, string $establishmentId): bool
    {
        return in_array($establishmentId, $this->grants[$principal->userId] ?? [], true);
    }
}
