<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Domain;

enum StaffStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Left = 'left';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
