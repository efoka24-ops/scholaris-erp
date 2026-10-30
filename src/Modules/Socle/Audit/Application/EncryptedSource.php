<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Application;

use Generator;
use InflateContext;
use RuntimeException;

/** Lit une sauvegarde écrite par EncryptedSink : déchiffre, décompresse et renvoie les événements JSON un par un. */
final class EncryptedSource
{
    public function __construct(private readonly string $path, private readonly string $key)
    {
    }

    /** @return Generator<int, array<string, mixed>> */
    public function lines(): Generator
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Sauvegarde illisible : ' . $this->path);
        }
        try {
            $magic = (string) fread($handle, strlen(BackupService::MAGIC));
            if ($magic !== BackupService::MAGIC) {
                throw new RuntimeException('Ce fichier n\'est pas une sauvegarde SCHOLARIS.');
            }
            $flag = (string) fread($handle, 1);
            $header = (string) fread($handle, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (!in_array($flag, ['Z', 'N'], true) || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('En-tête de sauvegarde invalide.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $inflate = null;
            if ($flag === 'Z') {
                $context = inflate_init(ZLIB_ENCODING_GZIP);
                if ($context === false) {
                    throw new RuntimeException('zlib est nécessaire pour lire cette sauvegarde.');
                }
                $inflate = $context;
            }

            $buffer = '';
            $final = false;
            while (!$final) {
                $length = fread($handle, 4);
                if ($length === false || strlen($length) !== 4) {
                    throw new RuntimeException('Sauvegarde tronquée.');
                }
                $unpacked = unpack('N', $length);
                $size = $unpacked === false ? 0 : (int) $unpacked[1];
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > 65536 + 1024) {
                    throw new RuntimeException('Bloc de sauvegarde invalide.');
                }
                $cipher = '';
                while (strlen($cipher) < $size) {
                    $part = fread($handle, max(1, $size - strlen($cipher)));
                    if ($part === false || $part === '') {
                        throw new RuntimeException('Sauvegarde tronquée.');
                    }
                    $cipher .= $part;
                }
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($opened === false) {
                    throw new RuntimeException('Déchiffrement impossible : clé incorrecte ou fichier altéré.');
                }
                [$plain, $tag] = $opened;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                if ($inflate !== null) {
                    $plain = $this->inflate($inflate, $plain, $final);
                }
                $buffer .= $plain;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    if ($line !== '') {
                        yield (array) json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            }
            if (fread($handle, 1) !== '' && !feof($handle)) {
                throw new RuntimeException('Données après la fin de la sauvegarde.');
            }
            if (trim($buffer) !== '') {
                throw new RuntimeException('Sauvegarde invalide : ligne incomplète.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function inflate(InflateContext $context, string $data, bool $final): string
    {
        $out = inflate_add($context, $data, $final ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
        if ($out === false) {
            throw new RuntimeException('Décompression impossible : sauvegarde altérée.');
        }

        return $out;
    }
}
