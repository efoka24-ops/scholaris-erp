<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use RuntimeException;

/**
 * Chiffrement des données de santé avec libsodium (secretbox). La clé vient de la configuration (`people.secret_key`)
 * ou de la variable d'environnement APP_KEY, jamais du dépôt ; sans clé, toute opération échoue.
 */
final class HealthCipher
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
            throw new RuntimeException('Donnée chiffrée illisible.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if ($plain === false) {
            throw new RuntimeException('Donnée chiffrée invalide ou clé différente.');
        }

        return $plain;
    }

    private function key(): string
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Aucune clé de chiffrement : définissez APP_KEY dans l\'environnement.');
        }

        return sodium_crypto_generichash('people-health|' . $this->secretKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
