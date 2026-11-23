<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use OTPHP\TOTP;
use Scholaris\Modules\Socle\Identity\Domain\User;

/**
 * Contrôle d'un code TOTP à 6 chiffres (RFC 6238, période de 30 s, tolérance d'un pas de chaque côté). Partagé par la
 * double authentification (TwoFactorService) et par la connexion par application d'authentification (AuthService).
 */
final class TotpMatcher
{
    private const PERIOD = 30;

    public function __construct(
        private readonly SecretCipher $cipher,
        private readonly Moment $moment,
    ) {
    }

    /** Pas TOTP correspondant au code, ou null (secret absent, code mal formé ou faux). */
    public function matchingStep(User $user, string $code): ?int
    {
        $code = trim($code);
        if ($user->twoFactorSecret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $secret = $this->cipher->decrypt($user->twoFactorSecret);
        if ($secret === '') {
            return null;
        }
        $totp = TOTP::createFromSecret($secret);
        $current = intdiv($this->moment->timestamp(), self::PERIOD);
        $found = null;
        foreach ([0, -1, 1] as $offset) {
            if (hash_equals($totp->at(max(0, ($current + $offset) * self::PERIOD)), $code)) {
                $found ??= $current + $offset;
            }
        }

        return $found;
    }
}
