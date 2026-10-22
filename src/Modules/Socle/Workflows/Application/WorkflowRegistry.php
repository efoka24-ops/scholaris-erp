<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Application;

use DI\Container;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowDefinitions;

/** Découvre les circuits déclarés par les applications (`workflows.definitions.*`, Contracts\WorkflowDefinitions). */
final class WorkflowRegistry
{
    /** @var array<string, Circuit>|null */
    private ?array $circuits = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<Circuit> triés par application puis par clé */
    public function all(): array
    {
        $circuits = $this->load();
        ksort($circuits);

        return array_values($circuits);
    }

    public function find(string $application, string $circuit): ?Circuit
    {
        return $this->load()[$application . '/' . $circuit] ?? null;
    }

    /** @return array<string, Circuit> */
    private function load(): array
    {
        if ($this->circuits !== null) {
            return $this->circuits;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, WorkflowDefinitions::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if (!$provider instanceof WorkflowDefinitions) {
                    continue;
                }
                foreach ($provider->circuits() as $circuit) {
                    $found[$circuit->application . '/' . $circuit->key] = $circuit;
                }
            }
        }

        return $this->circuits = $found;
    }
}
