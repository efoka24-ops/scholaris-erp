<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Formats des numéros : reçu R-<CODE>-<AA>-<5 chiffres> et référence famille FAM-<4 chiffres au moins>. */
final class Numbers
{
    public static function receipt(string $establishmentCode, int $year, int $sequence): string
    {
        return sprintf('R-%s-%02d-%05d', $establishmentCode, $year % 100, $sequence);
    }

    public static function familyReference(int $sequence): string
    {
        return sprintf('FAM-%04d', $sequence);
    }

    /** Code d'établissement pour les reçus : initiales des mots (ou 4 premières lettres), en majuscules sans accent. */
    public static function codeFromName(string $name): string
    {
        $ascii = strtoupper(strtr($name, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C']));
        $words = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $ascii) ?: []));
        if ($words === []) {
            return 'ETAB';
        }
        if (count($words) === 1) {
            return substr($words[0], 0, 4);
        }

        return substr(implode('', array_map(static fn (string $w): string => $w[0], $words)), 0, 6);
    }
}
