<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies;

use Scholaris\Modules\Apps\Higher\Deliberation\Domain\LmdCalculator;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Stratégie de calcul LMD de l'IPES (S6), enregistrée sous `assessment.rule_strategies.higher_deliberation` : les règles de
 * calcul d'une filière LMD (ou de licence professionnelle, certificat) la nomment par la clé `higher_lmd`.
 */
final class HigherLmdStrategy implements RuleStrategy
{
    public const KEY = 'higher_lmd';

    public function __construct(private readonly LmdCalculator $calculator = new LmdCalculator())
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function optionErrors(array $options): array
    {
        return OptionChecks::errors($options);
    }

    public function computeLearner(CalculationRules $rules, array $units, string $session): array
    {
        return $this->calculator->semester($rules, $units, $session);
    }

    public function decideYear(CalculationRules $rules, array $periods, array $context): array
    {
        return $this->calculator->year($rules, $periods, $context);
    }
}
