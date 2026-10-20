<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Infrastructure;

use RuntimeException;

/**
 * Chiffrement de la clé d'appareil au repos (libsodium, secretbox XSalsa20-Poly1305). La signature HMAC exige la clé en clair
 * côté serveur : on ne peut donc pas la hacher comme un mot de passe. Elle est conservée chiffrée avec une clé du serveur
 * (`sync.secret_key`, à défaut variable d'environnement APP_KEY, jamais dans le dépôt), dérivée avec un contexte propre au
 * module : une base volée sans cette clé ne permet pas de forger un lot. Sans clé du serveur, toute opération échoue.
 */
final class DeviceKeyCipher
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
            throw new RuntimeException('Clé d\'appareil illisible.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if ($plain === false) {
            throw new RuntimeException('Clé d\'appareil invalide ou clé du serveur différente.');
        }

        return $plain;
    }

    private function key(): string
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Aucune clé de chiffrement : définissez APP_KEY (ou sync.secret_key) dans l\'environnement.');
        }

        return sodium_crypto_generichash('sync-device|' . $this->secretKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
