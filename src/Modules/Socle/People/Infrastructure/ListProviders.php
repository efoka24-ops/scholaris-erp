<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use DI\Container;

/** Découvre les fournisseurs d'application enregistrés sous des entrées de liste du conteneur (`<préfixe><application>`), triés par nom. */
final class ListProviders
{
    /** @var array<string, list<object>> */
    private array $cache = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return list<T>
     */
    public function all(string $prefix, string $type): array
    {
        $key = $prefix . '|' . $type;
        if (!isset($this->cache[$key])) {
            $found = [];
            $names = $this->container->getKnownEntryNames();
            sort($names);
            foreach ($names as $name) {
                if (!str_starts_with($name, $prefix)) {
                    continue;
                }
                foreach ((array) $this->container->get($name) as $entry) {
                    $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                    if ($provider instanceof $type) {
                        $found[spl_object_id($provider)] = $provider;
                    }
                }
            }
            $this->cache[$key] = array_values($found);
        }

        $providers = [];
        foreach ($this->cache[$key] as $provider) {
            if ($provider instanceof $type) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
