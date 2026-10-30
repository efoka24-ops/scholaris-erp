<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/** Type d'établissement : unique et immuable après la création (il détermine l'application qui le gère). */
enum EstablishmentType: string
{
    case Nursery = 'nursery';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Higher = 'higher';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
