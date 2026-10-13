<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/** Jeton d'accès opaque (seule son empreinte SHA-256 est conservée). */
final class AccessToken
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $deviceId,
        public readonly bool $remember,
        public readonly string $expiresAt,
        public readonly bool $needsTwoFactor,
        public readonly ?string $twoFactorVerifiedAt,
        public readonly int $twoFactorAttempts,
        public readonly ?string $activeEstablishmentId,
        public readonly ?string $activeRole,
        public readonly ?string $lastUsedAt,
        public readonly ?string $revokedAt,
    ) {
    }

    /** Vrai tant que la double authentification exigée n'a pas été franchie. */
    public function isPendingTwoFactor(): bool
    {
        return $this->needsTwoFactor && $this->twoFactorVerifiedAt === null;
    }
}
