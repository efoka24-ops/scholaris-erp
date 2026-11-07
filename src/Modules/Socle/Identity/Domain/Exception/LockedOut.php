<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain\Exception;

use RuntimeException;

/** Verrouillage temporaire après trop d'échecs de connexion. */
final class LockedOut extends RuntimeException
{
    public function __construct(public readonly string $lockedUntil, public readonly int $retryAfter)
    {
        parent::__construct('Compte temporairement verrouillé.');
    }
}
