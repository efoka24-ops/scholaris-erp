<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use DI\Container;
use Scholaris\Modules\Socle\Organisation\Contracts\BalanceCarryOver;
use Scholaris\Modules\Socle\Organisation\Contracts\ClosureCheck;
use Scholaris\Modules\Socle\Organisation\Contracts\PromotionPlanner;
use Scholaris\Modules\Socle\Organisation\Contracts\TypedPromotionPlanner;

/**
 * Découvre les fournisseurs des contrats sortants de clôture, déclarés dans le conteneur par les modules propriétaires
 * (entrées de liste `organisation.closure.checks.*`, `.carry_over.*`, `.promotion.*`). Plusieurs modules peuvent s'enregistrer
 * sans se marcher dessus, contrairement à une liaison unique.
 */
final class ClosureProviders
{
    public const CHECKS = 'organisation.closure.checks.';
    public const CARRY_OVER = 'organisation.closure.carry_over.';
    public const PROMOTION = 'organisation.closure.promotion.';

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<ClosureCheck> */
    public function checks(): array
    {
        return $this->resolve(self::CHECKS, ClosureCheck::class);
    }

    /** @return list<BalanceCarryOver> */
    public function carryOvers(): array
    {
        return $this->resolve(self::CARRY_OVER, BalanceCarryOver::class);
    }

    /** @return list<PromotionPlanner> */
    public function promoters(): array
    {
        return $this->resolve(self::PROMOTION, PromotionPlanner::class);
    }

    /**
     * Planificateurs applicables à un type d'établissement (D8) : ceux qui visent ce type (TypedPromotionPlanner) remplacent les
     * génériques ; à défaut, les génériques seuls. Un planificateur typé pour un autre type n'est jamais appelé.
     *
     * @return list<PromotionPlanner>
     */
    public function promotersFor(string $establishmentType): array
    {
        $typed = [];
        $generic = [];
        foreach ($this->promoters() as $planner) {
            if ($planner instanceof TypedPromotionPlanner) {
                if (in_array($establishmentType, $planner->establishmentTypes(), true)) {
                    $typed[] = $planner;
                }
                continue;
            }
            $generic[] = $planner;
        }

        return $typed !== [] ? $typed : $generic;
    }

    /**
     * @template T of object
     * @param class-string<T> $interface
     * @return list<T>
     */
    private function resolve(string $prefix, string $interface): array
    {
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $class) {
                $provider = is_string($class) ? $this->container->get($class) : $class;
                if ($provider instanceof $interface) {
                    $found[$provider::class] = $provider;
                }
            }
        }

        return array_values($found);
    }
}
