<?php

declare(strict_types=1);

namespace Tests\Support\Organisation;

use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUsage;
use Scholaris\Modules\Socle\Organisation\Contracts\PeriodUse;

/** Doublure du contrat PeriodUsage : usages fixés par le test, période par période. */
final class FakePeriodUsage implements PeriodUsage
{
    /** @var array<string, list<PeriodUse>> */
    public array $uses = [];

    public function uses(string $tenantId, string $establishmentId, string $periodId): array
    {
        return $tenantId === 't1' && $establishmentId === 'e1' ? ($this->uses[$periodId] ?? []) : [];
    }
}
