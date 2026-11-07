<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureFinding;

/** Contrôle de clôture « Feuilles de compétences non verrouillées » (fournisseur `organisation.closure.checks.primary`). */
final class SheetsClosureCheck implements ClosureCheck
{
    public function __construct(private readonly ClosureReadiness $readiness)
    {
    }

    public function key(): string
    {
        return ClosureReadiness::SHEETS;
    }

    public function evaluate(string $tenantId, string $establishmentId, string $academicYearId): ClosureFinding
    {
        return $this->readiness->sheets($tenantId, $establishmentId, $academicYearId)['finding'];
    }
}
