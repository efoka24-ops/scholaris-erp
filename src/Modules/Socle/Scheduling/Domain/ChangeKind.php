<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Domain;

/** Modification ponctuelle d'une occurrence d'un créneau. */
enum ChangeKind: string
{
    case Cancelled = 'cancelled';
    case Moved = 'moved';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => 'annulé',
            self::Moved => 'déplacé',
            self::Replaced => 'assuré par un remplaçant',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
