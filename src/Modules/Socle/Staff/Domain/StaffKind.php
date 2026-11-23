<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Domain;

enum StaffKind: string
{
    case Permanent = 'permanent';
    case Vacataire = 'vacataire';
    case Invite = 'invite';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
