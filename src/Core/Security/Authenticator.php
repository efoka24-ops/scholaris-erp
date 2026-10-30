<?php

declare(strict_types=1);

namespace Scholaris\Core\Security;

use Psr\Http\Message\ServerRequestInterface;

/** Identifie l'appelant à partir de la requête (jeton, cookie). Implémenté par le module Identity (phase 3). */
interface Authenticator
{
    public function authenticate(ServerRequestInterface $request): ?Principal;
}
