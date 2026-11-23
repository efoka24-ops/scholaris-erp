<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Infrastructure;

use RuntimeException;
use Scholaris\Core\Config\Config;

/**
 * Chiffrement des données de santé de l'infirmerie (libsodium secretbox, clé dérivée propre au secondaire). Clé lue dans la
 * configuration `secondary.health_key`, à défaut `people.secret_key`, à défaut APP_KEY de l'environnement ; jamais dans le dépôt.
 * Sans clé, l'écriture et la lecture des notes et du dossier échouent (aucune donnée de santé en clair).
 */
class HealthCipher
{
    public function __construct(private readonly string $secretKey)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $key = (string) $config->get('secondary.health_key', '');
        if ($key === '') {
            $key = (string) $config->get('people.secret_key', '');
        }

        return new self($key !== '' ? $key : (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? ''));
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
            throw new RuntimeException('Donnée de santé illisible.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($plain === false) {
            throw new RuntimeException('Donnée de santé invalide ou clé différente.');
        }

        return $plain;
    }

    private function key(): string
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Aucune clé de chiffrement des données de santé : définissez APP_KEY dans l\'environnement.');
        }

        return sodium_crypto_generichash('secondary-health|' . $this->secretKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
