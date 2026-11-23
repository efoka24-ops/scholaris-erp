<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/** Pseudonyme stable d'une personne effacée (même valeur dans tous les modules, non réversible). */
final class Pseudonym
{
    public const PREFIX = 'anon-';

    public static function of(string $tenantId, string $personId): string
    {
        return self::PREFIX . substr(hash('sha256', $tenantId . '|' . $personId), 0, 12);
    }
}
