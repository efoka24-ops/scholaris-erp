<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/**
 * Générateur de mot de passe provisoire : 12 caractères tirés au hasard cryptographique, sans caractère ambigu
 * (ni 0/O, 1/l/I), avec au moins une majuscule, une minuscule et un chiffre. Il est remis une fois à l'administrateur, qui le
 * transmet à la personne ; celle-ci doit le remplacer à sa première connexion.
 */
final class ProvisionalPassword
{
    public const LENGTH = 12;
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';
    private const DIGITS = '23456789';

    public static function generate(): string
    {
        $all = self::UPPER . self::LOWER . self::DIGITS;
        $chars = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS)];
        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($all);
        }
        // Mélange de Fisher-Yates avec le hasard cryptographique : la place des trois classes obligatoires n'est pas prévisible.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
