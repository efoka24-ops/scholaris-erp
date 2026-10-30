<?php

declare(strict_types=1);

namespace Tests\Support\PublicSchools;

use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/** Doublure de CurrentPeriod : une année scolaire unique, du 1er septembre 2026 au 30 juin 2027, avec des jours fermés optionnels. */
final class FakeCurrentPeriod implements CurrentPeriod
{
    /** @var list<string> */
    public array $closed = [];

    public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo
    {
        $day = substr($date, 0, 10);
        if ($tenantId !== 't1' || $day < '2026-09-01' || $day > '2027-06-30' || in_array($day, $this->closed, true)) {
            return null;
        }

        return new PeriodInfo('per1', 'ay1', '2026-2027', 'trimester', 1, '2026-09-01', '2027-06-30');
    }

    public function allAt(string $tenantId, string $establishmentId, string $date): array
    {
        $period = $this->at($tenantId, $establishmentId, $date);

        return $period === null ? [] : [$period];
    }
}
