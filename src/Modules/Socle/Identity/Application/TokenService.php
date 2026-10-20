<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use Scholaris\Modules\Socle\Identity\Domain\AccessToken;
use Scholaris\Modules\Socle\Identity\Infrastructure\PdoAccessTokenRepository;

/** Émission et contrôle des jetons d'accès opaques (43 caractères aléatoires, empreinte SHA-256 en base). */
final class TokenService
{
    public const PREFIX = 'sch_';
    public const TTL_DEFAULT = 28800;      // 8 heures
    public const TTL_REMEMBER = 2592000;   // 30 jours

    public function __construct(
        private readonly PdoAccessTokenRepository $tokens,
        private readonly Moment $moment,
    ) {
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * `passwordConfirmed` : la session s'ouvre par saisie du mot de passe, ce qui vaut confirmation récente (PdoRecentSecondFactor).
     *
     * @return array{token: string, expires_at: string, id: string} le jeton en clair n'existe que dans cette réponse
     */
    public function issue(string $tenantId, string $userId, string $deviceId, bool $remember, bool $needsTwoFactor, bool $passwordConfirmed = false): array
    {
        $plain = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->moment->in($remember ? self::TTL_REMEMBER : self::TTL_DEFAULT);
        $id = $this->tokens->insert($tenantId, $userId, $deviceId, self::hash($plain), $remember, $expiresAt, $needsTwoFactor, $this->moment->iso(), $passwordConfirmed ? $this->moment->iso() : null);

        return ['token' => $plain, 'expires_at' => $expiresAt, 'id' => $id];
    }

    /** Jeton valide (connu, non révoqué, non expiré) ou null. */
    public function validate(string $plain): ?AccessToken
    {
        $token = $this->tokens->findByHash(self::hash($plain));
        if ($token === null || $token->revokedAt !== null || $token->expiresAt <= $this->moment->iso()) {
            return null;
        }

        return $token;
    }

    public function touchIfStale(AccessToken $token): void
    {
        $now = $this->moment->iso();
        if ($token->lastUsedAt === null || $this->moment->secondsBetween($token->lastUsedAt, $now) >= 60) {
            $this->tokens->touch($token->tenantId, $token->id, $now);
        }
    }
}
