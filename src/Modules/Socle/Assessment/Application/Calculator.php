<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleCalculator;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;

/** Implémentation du contrat RuleCalculator : règles en vigueur d'une structure et calcul à blanc pour les applications. */
final class Calculator implements RuleCalculator
{
    public function __construct(
        private readonly RuleService $rules,
        private readonly RuleCalculation $calculation,
        private readonly RuleEngine $engine,
    ) {
    }

    public function rulesFor(string $tenantId, string $establishmentId, ?string $academicYearId, ?string $structureId = null): CalculationRules
    {
        return $this->rules->activeRules($tenantId, $establishmentId, $academicYearId, $structureId)->toCalculationRules();
    }

    public function computeLearner(CalculationRules $rules, array $units, string $session = 'normal'): array
    {
        return $this->calculation->compute(RuleSet::fromCalculationRules($rules), $units, $session);
    }

    public function decideYear(CalculationRules $rules, array $periods, array $context = []): array
    {
        return $this->calculation->decideYear(RuleSet::fromCalculationRules($rules), $periods, $context);
    }

    public function ranks(array $averages): array
    {
        return $this->engine->ranks($averages);
    }
}
