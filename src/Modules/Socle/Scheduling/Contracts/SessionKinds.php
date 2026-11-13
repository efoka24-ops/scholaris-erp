<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/** Natures de séance d'un créneau (D12) : cours magistral, travaux dirigés, travaux pratiques, autre. Null = non précisée. */
final class SessionKinds
{
    public const CM = 'cm';
    public const TD = 'td';
    public const TP = 'tp';
    public const OTHER = 'other';

    public const ALL = [self::CM, self::TD, self::TP, self::OTHER];

    private function __construct()
    {
    }
}
