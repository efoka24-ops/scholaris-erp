<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Security\Authenticator;
use Scholaris\Core\Security\Principal;

/** Doublure : « Authorization: Bearer user:tenant » identifie l'appelant. */
final class StubAuthenticator implements Authenticator
{
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        if (preg_match('/^Bearer ([\w-]+):([\w-]+)$/', $request->getHeaderLine('Authorization'), $m) !== 1) {
            return null;
        }

        return new Principal($m[1], $m[2]);
    }
}
