<?php

declare(strict_types=1);

namespace Tests\Support\Organisation;

use RuntimeException;
use Scholaris\Modules\Socle\Organisation\Contracts\BalanceCarryOver;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureStepResult;
use Scholaris\Modules\Socle\Organisation\Contracts\PromotionPlanner;

/** Doublure des trois contrats sortants de clôture, pilotable par le test. */
final class ClosureDoubles implements ClosureCheck, BalanceCarryOver, PromotionPlanner
{
    public int $unpaid = 0;
    public bool $failChecks = false;
    public int $carryCalls = 0;
    public int $promoteCalls = 0;
    public bool $failPromotion = false;

    public function __construct(private readonly string $checkKey = 'billing.unpaid')
    {
    }

    public function key(): string
    {
        return $this->checkKey;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        if ($this->failChecks) {
            throw new RuntimeException('Base indisponible');
        }

        return ClosureFinding::of($this->checkKey, 'Impayés', $this->unpaid);
    }

    public function carryOver(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        ++$this->carryCalls;

        return new ClosureStepResult(ClosureStepResult::DONE, '12 soldes reportés.', ['balances' => 12]);
    }

    public function promote(string $tenantId, string $establishmentId, string $fromYearId, string $toYearId): ClosureStepResult
    {
        ++$this->promoteCalls;
        if ($this->failPromotion) {
            throw new RuntimeException('Service des inscriptions indisponible');
        }

        return new ClosureStepResult(ClosureStepResult::DONE, '40 élèves promus.', ['promoted' => 40]);
    }
}
