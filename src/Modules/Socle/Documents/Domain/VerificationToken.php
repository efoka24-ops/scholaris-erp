<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

/** Jeton de vérification : 128 bits aléatoires, non séquentiel, en base64url (22 caractères). */
final class VerificationToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{22}$/', $token) === 1;
    }
}
