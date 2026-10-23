<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/** Modules du socle activables par établissement (selon le contrat du client). */
final class ModuleCatalog
{
    public const KEYS = [
        'people', 'billing', 'messaging', 'documents', 'scheduling',
        'attendance', 'assessment', 'staff', 'reporting',
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $module): bool
    {
        return in_array($module, self::KEYS, true);
    }
}
