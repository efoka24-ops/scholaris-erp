<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Domain;

/** Masque public du titulaire : initiale du nom, trois points ronds, prénom (« F••• Nadia »). */
final class NameMask
{
    public static function mask(string $firstName, string $lastName): string
    {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $initial = mb_strtoupper(mb_substr($lastName !== '' ? $lastName : $firstName, 0, 1));

        return trim($initial . '•••' . ($lastName !== '' ? ' ' . $firstName : ''));
    }
}
