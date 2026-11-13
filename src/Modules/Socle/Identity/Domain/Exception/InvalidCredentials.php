<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain\Exception;

use RuntimeException;

/** Identifiant ou mot de passe incorrect (même erreur dans les deux cas). */
final class InvalidCredentials extends RuntimeException
{
    public function __construct(public readonly int $attemptsLeft)
    {
        parent::__construct('Identifiants incorrects.');
    }
}
