<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Domain;

/** Nature d'une entrée du calendrier académique d'une école (EX-ACA-06). */
enum CalendarEntryKind: string
{
    case Semester = 'semester';
    case ExamPeriod = 'exam_period';
    case ResitPeriod = 'resit_period';
    case Deliberation = 'deliberation';
    case Holiday = 'holiday';
    case EnrolmentPeriod = 'enrolment_period';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
