<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use DeflateContext;
use RuntimeException;

/**
 * Écrit des lignes JSON dans un fichier compressé (gzip si zlib) puis chiffré par flux (libsodium secretstream). Format du
 * fichier : « SCHBK1\n », un octet « Z » (compressé) ou « N », l'en-tête du flux (24 octets), puis des blocs
 * [longueur sur 4 octets][bloc chiffré et authentifié] ; le dernier bloc porte l'étiquette FINAL (détection de troncature).
 */
final class EncryptedSink
{
    private const BLOCK = 65536;

    /** @var resource|null */
    private $handle;
    private string $state;
    private string $buffer = '';
    private ?DeflateContext $deflate = null;
    private bool $compressed;

    public function __construct(string $path, string $key, bool $compress)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Fichier de sauvegarde impossible à créer : ' . $path);
        }
        $this->handle = $handle;
        $this->compressed = $compress && function_exists('deflate_init');
        if ($this->compressed) {
            $context = deflate_init(ZLIB_ENCODING_GZIP);
            $this->deflate = $context === false ? null : $context;
            $this->compressed = $this->deflate !== null;
        }
        [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($handle, BackupService::MAGIC . ($this->compressed ? 'Z' : 'N') . $header);
    }

    public function compressed(): bool
    {
        return $this->compressed;
    }

    /** @param array<string, mixed> $event */
    public function line(array $event): void
    {
        $data = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if ($this->deflate !== null) {
            $data = deflate_add($this->deflate, $data, ZLIB_NO_FLUSH);
            if ($data === false) {
                throw new RuntimeException('Compression impossible.');
            }
        }
        $this->buffer .= $data;
        while (strlen($this->buffer) > self::BLOCK) {
            $this->push(substr($this->buffer, 0, self::BLOCK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buffer = substr($this->buffer, self::BLOCK);
        }
    }

    public function close(): void
    {
        if ($this->deflate !== null) {
            $tail = deflate_add($this->deflate, '', ZLIB_FINISH);
            if ($tail === false) {
                throw new RuntimeException('Compression impossible.');
            }
            $this->buffer .= $tail;
        }
        while (strlen($this->buffer) > self::BLOCK) {
            $this->push(substr($this->buffer, 0, self::BLOCK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buffer = substr($this->buffer, self::BLOCK);
        }
        $this->push($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->buffer = '';
        $this->abort();
    }

    /** Ferme le fichier sans rien ajouter (échec en cours d'écriture). */
    public function abort(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    private function push(string $plain, int $tag): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('Sauvegarde déjà fermée.');
        }
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        fwrite($this->handle, pack('N', strlen($cipher)) . $cipher);
    }
}
