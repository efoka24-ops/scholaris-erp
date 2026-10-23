<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Domain;

/** Nature d'une séance et son coefficient d'équivalent TD (en millièmes : 1 CM = 1,5 ; 1 TD = 1 ; 1 TP = 0,75). */
enum SessionKind: string
{
    case Cm = 'cm';
    case Td = 'td';
    case Tp = 'tp';
    case Other = 'other';

    public function hetdPermille(): int
    {
        return match ($this) {
            self::Cm => 1500,
            self::Td, self::Other => 1000,
            self::Tp => 750,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
