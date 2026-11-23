<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Domain;

/** Types de congé et d'absence. */
enum LeaveType: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case Family = 'family';
    case Unpaid = 'unpaid';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
