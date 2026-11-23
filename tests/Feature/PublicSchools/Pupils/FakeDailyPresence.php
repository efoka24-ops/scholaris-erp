<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use Scholaris\Modules\Socle\Attendance\Contracts\DailyPresence;

/** Doublure des absents du jour : « client|établissement|date » => personnes absentes (appels validés). */
final class FakeDailyPresence implements DailyPresence
{
    /** @var array<string, list<string>> */
    private array $absent = [];

    /** @param list<string> $personIds */
    public function set(string $tenantId, string $establishmentId, string $date, array $personIds): void
    {
        $this->absent[$tenantId . '|' . $establishmentId . '|' . $date] = $personIds;
    }

    public function absentOn(string $tenantId, string $establishmentId, string $date, ?string $structureId = null, ?string $dayPart = null): array
    {
        $ids = $this->absent[$tenantId . '|' . $establishmentId . '|' . $date] ?? [];
        sort($ids);

        return $ids;
    }
}
