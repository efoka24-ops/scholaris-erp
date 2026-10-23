<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

interface PeriodAggregatingStrategy
{
    /**
     * @param list<array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}> $periods
     * @return array{average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}
     */
    public function aggregatePeriods(CalculationRules $rules, array $periods): array;
}
