<?php

declare(strict_types=1);

namespace Scholaris\Core\Support;

use Stringable;

/**
 * Identifiant ULID : 26 caractères Crockford base32, triables par ordre de création.
 * 48 bits d'horodatage en millisecondes, puis 80 bits aléatoires. Dans une même milliseconde,
 * la partie aléatoire est incrémentée : l'ordre de création est toujours respecté.
 */
final class Ulid implements Stringable
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $lastTime = -1;

    /** @var list<int> 16 valeurs de 5 bits (80 bits) */
    private static array $lastRandom = [];

    private readonly string $value;

    public function __construct(?string $value = null)
    {
        $this->value = $value !== null ? self::normalize($value) : self::generate();
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function toBase32(): string
    {
        return $this->value;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/', $value) === 1;
    }

    private static function normalize(string $value): string
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('ULID invalide.');
        }

        return strtoupper($value);
    }

    private static function generate(): string
    {
        $time = (int) floor(microtime(true) * 1000);
        if ($time <= self::$lastTime) {
            $time = self::$lastTime;
            self::$lastRandom = self::increment(self::$lastRandom);
        } else {
            self::$lastTime = $time;
            self::$lastRandom = self::freshRandom();
        }

        $encoded = '';
        for ($i = 9; $i >= 0; $i--) {
            $encoded .= self::ALPHABET[($time >> (5 * $i)) & 31];
        }
        foreach (self::$lastRandom as $digit) {
            $encoded .= self::ALPHABET[$digit];
        }

        return $encoded;
    }

    /** @return list<int> */
    private static function freshRandom(): array
    {
        $digits = [];
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $digits[] = (int) bindec($chunk);
        }

        return $digits;
    }

    /**
     * @param list<int> $digits
     * @return list<int>
     */
    private static function increment(array $digits): array
    {
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] < 31) {
                $digits[$i]++;

                return $digits;
            }
            $digits[$i] = 0;
        }

        return self::freshRandom();
    }
}
