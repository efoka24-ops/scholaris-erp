<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

use Psr\Http\Message\ServerRequestInterface;

/** Refus par défaut : tant qu'aucun module Identity n'est branché, personne n'est authentifié. */
final class NullAuthenticator implements Authenticator
{
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        return null;
    }
}
