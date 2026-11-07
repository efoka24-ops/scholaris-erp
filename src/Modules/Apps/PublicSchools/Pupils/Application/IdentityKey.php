<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

/** Clé de rapprochement des doublons (EX-ELV-03) : nom et prénom en minuscules, sans accent, ponctuation ni ordre. */
final class IdentityKey
{
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ù' => 'u',
        'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ];

    private function __construct()
    {
    }

    public static function of(string $lastName, string $firstName): string
    {
        $words = array_merge(self::words($lastName), self::words($firstName));
        sort($words);

        return mb_substr(implode(' ', $words), 0, 200);
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $ascii = strtr(mb_strtolower($text), self::ACCENTS);
        $clean = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        return array_values(array_filter(explode(' ', trim($clean)), static fn (string $word): bool => $word !== ''));
    }
}
