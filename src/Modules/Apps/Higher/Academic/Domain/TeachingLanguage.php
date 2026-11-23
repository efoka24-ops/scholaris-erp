<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Domain;

/** Langue d'enseignement d'une filière (EX-ACA-02) ; langue par défaut de ses documents (RG-DOC-8). */
enum TeachingLanguage: string
{
    case French = 'fr';
    case English = 'en';
    case Bilingual = 'bilingual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $l): string => $l->value, self::cases());
    }
}
