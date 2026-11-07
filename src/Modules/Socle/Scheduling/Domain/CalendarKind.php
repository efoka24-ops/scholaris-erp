<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Domain;

/** Nature d'une entrée du calendrier académique. */
enum CalendarKind: string
{
    case Semester = 'semester';
    case ExamPeriod = 'exam_period';
    case MakeupSession = 'makeup_session';
    case Deliberation = 'deliberation';
    case Holiday = 'holiday';
    case Closure = 'closure';
    case Event = 'event';

    /** Les jours fériés et fermetures suspendent les cours. */
    public function suspendsClasses(): bool
    {
        return $this === self::Holiday || $this === self::Closure;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
