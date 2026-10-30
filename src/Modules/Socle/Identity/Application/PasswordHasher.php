<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

/** Hachage Argon2id (ext-sodium via password_hash). Les coûts viennent de la configuration `identity.argon_*`. */
final class PasswordHasher
{
    public function __construct(
        private readonly ?int $memoryKib = null,
        private readonly ?int $timeCost = null,
    ) {
    }

    public function hash(string $password): string
    {
        $options = [];
        if ($this->memoryKib !== null) {
            $options['memory_cost'] = $this->memoryKib;
        }
        if ($this->timeCost !== null) {
            $options['time_cost'] = $this->timeCost;
        }

        return password_hash($password, PASSWORD_ARGON2ID, $options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Vérification factice (même coût qu'une vraie) pour qu'un identifiant inconnu ne se distingue pas
     * d'un mot de passe faux par le temps de réponse.
     */
    public function burn(string $password): void
    {
        password_verify($password, $this->dummy());
    }

    private function dummy(): string
    {
        static $cache = [];
        $key = ($this->memoryKib ?? 0) . '|' . ($this->timeCost ?? 0);

        return $cache[$key] ??= $this->hash('dummy-password-not-used');
    }
}
