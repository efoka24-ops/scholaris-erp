<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Modules\Socle\Identity\Domain\User;

/** Résultat d'une connexion réussie. Le jeton en clair n'est disponible qu'ici. */
final class LoginResult
{
    public function __construct(
        public readonly User $user,
        public readonly string $token,
        public readonly string $expiresAt,
        public readonly bool $newDevice,
        public readonly bool $twoFactorRequired,
        public readonly bool $twoFactorSetupRequired,
        public readonly bool $remember,
    ) {
    }
}
