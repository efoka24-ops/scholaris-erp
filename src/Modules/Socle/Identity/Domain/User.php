<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/** Compte de connexion d'une personne. `twoFactorSecret` est chiffré (voir SecretCipher). */
final class User
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const LOCKED = 'locked';

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $personId,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $passwordHash,
        public readonly string $locale,
        public readonly string $status,
        public readonly ?string $twoFactorSecret,
        public readonly ?string $twoFactorConfirmedAt,
        public readonly int $twoFactorLastStep,
        /** Mot de passe provisoire émis par l'administrateur : à remplacer à la première connexion. */
        public readonly bool $mustChangePassword = false,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function hasTwoFactor(): bool
    {
        return $this->twoFactorConfirmedAt !== null && $this->twoFactorSecret !== null;
    }
}
