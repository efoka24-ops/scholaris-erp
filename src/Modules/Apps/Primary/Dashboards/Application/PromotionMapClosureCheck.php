<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;

/** Contrôle de clôture « Table de passage incohérente » (fournisseur `organisation.closure.checks.primary`). */
final class PromotionMapClosureCheck implements ClosureCheck
{
    public function __construct(private readonly ClosureReadiness $readiness)
    {
    }

    public function key(): string
    {
        return ClosureReadiness::MAP;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        return $this->readiness->promotionMap($tenantId, $establishmentId)['finding'];
    }
}
