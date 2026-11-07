<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;

/** Stratégies de calcul enregistrées par les applications (S6), par clé. « standard » = moteur du socle. */
final class RuleStrategies
{
    /** @var array<string, RuleStrategy> */
    private array $byKey = [];

    /** @param list<RuleStrategy> $strategies */
    public function __construct(array $strategies = [])
    {
        foreach ($strategies as $strategy) {
            if ($strategy->key() !== RuleSet::STANDARD) {
                $this->byKey[$strategy->key()] = $strategy;
            }
        }
    }

    public function find(string $key): ?RuleStrategy
    {
        return $this->byKey[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $key === RuleSet::STANDARD || isset($this->byKey[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [RuleSet::STANDARD, ...array_keys($this->byKey)];
    }
}
