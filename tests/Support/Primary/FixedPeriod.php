<?php

declare(strict_types=1);

namespace Tests\Support\Primary;

use Scholaris\Modules\Socle\Organisation\Contracts\CurrentPeriod;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodInfo;

/** Doublure de CurrentPeriod : un trimestre `P-T1` de l'année `ay1` pour tout établissement, ou aucune période. */
final class FixedPeriod implements CurrentPeriod
{
    public bool $open = true;

    public function at(string $tenantId, string $establishmentId, string $date): ?PeriodInfo
    {
        return $this->open ? new PeriodInfo('P-T1', 'ay1', '2026-2027', 'trimester', 1, '2026-09-07', '2026-12-18') : null;
    }

    public function allAt(string $tenantId, string $establishmentId, string $date): array
    {
        $period = $this->at($tenantId, $establishmentId, $date);

        return $period === null ? [] : [$period];
    }
}
