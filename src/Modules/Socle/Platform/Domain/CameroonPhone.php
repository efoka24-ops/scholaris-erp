<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Domain;

/**
 * Numéro de téléphone mobile camerounais (mobile money Orange Money et MTN MoMo) : normalisé en +2376XXXXXXXX.
 * Accepte « 6 99 00 00 01 », « 237 699000001 », « 00237699000001 » et « +237 699 00 00 01 ».
 */
final class CameroonPhone
{
    private function __construct()
    {
    }

    /** Numéro normalisé (+237 suivi de 9 chiffres commençant par 6), ou null s'il n'est pas valide. */
    public static function normalize(string $raw): ?string
    {
        $clean = (string) preg_replace('/[\s.\-()]/', '', trim($raw));
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }
        if (preg_match('/^\+?237(6[0-9]{8})$/', $clean, $m) === 1 || preg_match('/^(6[0-9]{8})$/', $clean, $m) === 1) {
            return '+237' . $m[1];
        }

        return null;
    }

    /** Numéro masqué pour l'affichage : on garde l'indicatif, le premier chiffre et les trois derniers (+2376*****345). */
    public static function mask(string $phone): string
    {
        $length = strlen($phone);
        if ($length < 9) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 5) . str_repeat('*', $length - 8) . substr($phone, -3);
    }

    /** Les neuf derniers chiffres : comparaison de deux numéros écrits différemment. */
    public static function significant(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return substr($digits, -9);
    }
}
