<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;

/** Doublure de DailyPresence : absents d'une classe un jour donné, par demi-journée (appels validés fictifs). */
final class FakePresence implements DailyPresence
{
    /** @var array<string, list<string>> */
    private array $absent = [];

    /** @param list<string> $personIds */
    public function set(string $tenantId, string $establishmentId, string $date, string $classId, string $dayPart, array $personIds): void
    {
        $this->absent[implode('|', [$tenantId, $establishmentId, $date, $classId, $dayPart])] = $personIds;
    }

    public function absentOn(string $tenantId, string $establishmentId, string $date, ?string $structureId = null, ?string $dayPart = null): array
    {
        $ids = [];
        foreach ([self::MORNING, self::AFTERNOON] as $part) {
            if ($dayPart === null || $dayPart === $part) {
                array_push($ids, ...($this->absent[implode('|', [$tenantId, $establishmentId, $date, (string) $structureId, $part])] ?? []));
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
