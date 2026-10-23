<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Modules\Socle\Identity\Domain\AccessToken;

/** Jeton de la requête en cours, déposé par l'authentificateur et lu par les contrôleurs d'Identity. */
final class CurrentSession
{
    private ?AccessToken $token = null;

    public function set(?AccessToken $token): void
    {
        $this->token = $token;
    }

    public function token(): ?AccessToken
    {
        return $this->token;
    }
}
