<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Infrastructure;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use Scholaris\Modules\Socle\Scheduling\Application\ScheduleResolver;
use Scholaris\Modules\Socle\Scheduling\Contracts\CourseUnitOccurrences;
use Scholaris\Modules\Socle\Scheduling\Contracts\OccurrenceInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\SessionKinds;

/** Occurrences d'un élément constitutif calculées jour par jour par le résolveur d'emploi du temps (D13). */
final class ResolvedCourseUnitOccurrences implements CourseUnitOccurrences
{
    private const MAX_DAYS = 366;

    public function __construct(private readonly SchedulingStore $store, private readonly ScheduleResolver $resolver)
    {
    }

    public function heldOf(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        return array_values(array_filter($this->all($tenantId, $establishmentId, $courseUnitId, $from, $to), static fn (OccurrenceInfo $o): bool => $o->isHeld()));
    }

    public function scheduledOf(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        return array_values(array_filter($this->all($tenantId, $establishmentId, $courseUnitId, $from, $to), static fn (OccurrenceInfo $o): bool => $o->isScheduled()));
    }

    public function heldMinutesByKind(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        $minutes = [];
        foreach ($this->heldOf($tenantId, $establishmentId, $courseUnitId, $from, $to) as $occurrence) {
            $kind = $occurrence->sessionKind ?? SessionKinds::OTHER;
            $minutes[$kind] = ($minutes[$kind] ?? 0) + $occurrence->minutes;
        }
        ksort($minutes);

        return $minutes;
    }

    /** @return list<OccurrenceInfo> */
    private function all(string $tenantId, string $establishmentId, string $courseUnitId, string $from, string $to): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Les bornes doivent être au format AAAA-MM-JJ.');
        }
        if ($end < $start || $this->store->where('timetable_slots', $tenantId, $establishmentId, ['course_unit_id' => $courseUnitId]) === []) {
            return [];
        }
        $end = min($end, $start->add(new DateInterval('P' . (self::MAX_DAYS - 1) . 'D')));
        $out = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->add(new DateInterval('P1D'))) as $day) {
            foreach ($this->resolver->occurrences($tenantId, $establishmentId, $day->format('Y-m-d')) as $occurrence) {
                if ($occurrence->courseUnitId === $courseUnitId) {
                    $out[] = $occurrence->toInfo();
                }
            }
        }

        return $out;
    }
}
