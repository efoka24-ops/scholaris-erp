<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scholaris\Modules\Socle\Sync\Infrastructure\DeviceKeyCipher;

/** Chiffrement de la clé d'appareil au repos (libsodium secretbox) : jamais en clair, aucune clé = refus. */
final class DeviceKeyCipherTest extends TestCase
{
    public function testLaCleSeChiffreEtSeDechiffre(): void
    {
        $cipher = new DeviceKeyCipher('cle-serveur-de-test');
        $key = bin2hex(random_bytes(32));

        $encrypted = $cipher->encrypt($key);

        self::assertStringNotContainsString($key, $encrypted);
        self::assertSame($key, $cipher->decrypt($encrypted));
        self::assertNotSame($encrypted, $cipher->encrypt($key), 'Nonce aléatoire : deux chiffrements diffèrent.');
    }

    public function testUneAutreCleServeurNeDechiffrePas(): void
    {
        $encrypted = (new DeviceKeyCipher('cle-a'))->encrypt('secret');

        $this->expectException(RuntimeException::class);
        (new DeviceKeyCipher('cle-b'))->decrypt($encrypted);
    }

    public function testUnTexteAltereOuIllisibleEstRefuse(): void
    {
        $cipher = new DeviceKeyCipher('cle-a');
        $encrypted = $cipher->encrypt('secret');
        $raw = (string) base64_decode($encrypted, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';

        foreach ([base64_encode($raw), 'pas-du-base64!', '', base64_encode('court')] as $bad) {
            try {
                $cipher->decrypt($bad);
                self::fail('Le déchiffrement aurait dû échouer pour : ' . $bad);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSansCleToutEstRefuse(): void
    {
        $cipher = new DeviceKeyCipher('');

        $this->expectException(RuntimeException::class);
        $cipher->encrypt('secret');
    }

    public function testLaCleDeriveeEstSeparee(): void
    {
        $sync = (new DeviceKeyCipher('meme-cle'))->encrypt('secret');

        $other = new class ('meme-cle') {
            public function __construct(private readonly string $secret)
            {
            }

            public function open(string $encoded): string|false
            {
                $raw = (string) base64_decode($encoded, true);
                $key = sodium_crypto_generichash($this->secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

                return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            }
        };

        self::assertFalse($other->open($sync), 'La clé est dérivée avec un contexte propre au module : un autre module ne peut pas la lire.');
    }
}
