<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Application;

use DI\Container;
use Scholaris\Modules\Socle\Reporting\Contracts\DashboardDefinition;

/** Découvre les fournisseurs déclarés dans le conteneur par les modules et applications (voir IndicatorProvider). */
final class ProviderRegistry
{
    public const INDICATORS = 'reporting.indicators.';
    public const TODO = 'reporting.todo.';
    public const DASHBOARDS = 'reporting.dashboards.';

    public function __construct(private readonly Container $container)
    {
    }

    /** Tableau de bord déclaré par une application (S21) sous `reporting.dashboards.*`, ou null. */
    public function dashboard(string $key): ?DashboardDefinition
    {
        foreach ($this->resolveAll(self::DASHBOARDS, DashboardDefinition::class) as $definition) {
            if ($definition->key() === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Comme resolve, sans dédoublonner par classe (plusieurs instances d'une même classe de définition sont permises).
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return list<T>
     */
    private function resolveAll(string $prefix, string $interface): array
    {
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $item = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($item instanceof $interface) {
                    $found[] = $item;
                }
            }
        }

        return $found;
    }

    /**
     * @template T of object
     * @param class-string<T> $interface
     * @return list<T>
     */
    public function resolve(string $prefix, string $interface): array
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
