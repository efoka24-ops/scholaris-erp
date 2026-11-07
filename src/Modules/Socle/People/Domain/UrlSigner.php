<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

use RuntimeException;

/** Signature HMAC-SHA256 d'une URL temporaire : sujet + expiration. Sans clé configurée, toute opération échoue. */
final class UrlSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(string $subject, int $expires): string
    {
        if ($this->secret === '') {
            throw new RuntimeException('Aucune clé de signature : définissez APP_KEY dans l\'environnement.');
        }

        return hash_hmac('sha256', $subject . '|' . $expires, hash('sha256', 'people-url|' . $this->secret, true));
    }

    public function verify(string $subject, int $expires, string $signature, int $now): bool
    {
        return $expires >= $now && hash_equals($this->sign($subject, $expires), $signature);
    }
}
