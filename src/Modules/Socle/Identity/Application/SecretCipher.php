<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Application;

use RuntimeException;

/**
 * Chiffrement des secrets conservés en base (secret TOTP) avec libsodium (XSalsa20-Poly1305).
 * La clé vient de la configuration (`identity.secret_key` ou variable d'environnement APP_KEY) ; sans clé,
 * toute opération échoue : un secret n'est jamais stocké en clair.
 */
final class SecretCipher
{
    public function __construct(private readonly string $secretKey)
    {
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Secret chiffré illisible.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
        if ($plain === false) {
            throw new RuntimeException('Secret chiffré invalide ou clé différente.');
        }

        return $plain;
    }

    private function key(): string
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Aucune clé de chiffrement : définissez APP_KEY dans l\'environnement.');
        }

        return sodium_crypto_generichash($this->secretKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
