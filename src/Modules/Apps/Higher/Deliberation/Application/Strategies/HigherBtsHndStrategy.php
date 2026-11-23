<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies;

use Scholaris\Modules\Apps\Higher\Deliberation\Domain\BtsHndCalculator;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/** Stratégie de calcul BTS/HND (formation interne) de l'IPES (S6), clé `higher_bts_hnd`. */
final class HigherBtsHndStrategy implements RuleStrategy
{
    public const KEY = 'higher_bts_hnd';

    public function __construct(private readonly BtsHndCalculator $calculator = new BtsHndCalculator())
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
