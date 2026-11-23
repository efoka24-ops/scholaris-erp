<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

/** Normalisation des noms et téléphones pour la comparaison (détection de doublons). */
final class Names
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ì' => 'i',
        'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u',
        'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    /** Prénoms et noms réduits à des mots en minuscules sans accent, triés (l'ordre « Nom Prénom » ou l'inverse est indifférent). */
    public static function key(string $firstName, string $lastName): string
    {
        $text = strtr(mb_strtolower($firstName . ' ' . $lastName), self::ACCENTS);
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** Chiffres seuls, sans l'indicatif 237 : « +237 6 77 12 34 56 » et « 677123456 » sont le même numéro. */
    public static function phoneKey(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) > 9 && str_starts_with($digits, '237')) {
            $digits = substr($digits, 3);
        }

        return $digits === '' ? null : $digits;
    }
}
