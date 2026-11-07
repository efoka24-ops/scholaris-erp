<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Domain;

/** Mode d'étude d'une filière (EX-ACA-02). */
enum StudyMode: string
{
    case Day = 'day';
    case Evening = 'evening';
    case WorkStudy = 'work_study';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
