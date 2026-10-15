<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain\Exception;

use RuntimeException;

/** Compte verrouillé durablement (action « Ce n'est pas moi ») : seule une réinitialisation du mot de passe le rouvre. */
final class AccountLocked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Compte verrouillé.');
    }
}
