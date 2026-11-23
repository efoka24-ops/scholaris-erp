<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use DI\Container;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentNumberProvider;

/** Découvre les fournisseurs de numéro d'inscription (entrées de liste `people.enrollment_number.providers.*`), triés par nom. */
final class EnrollmentNumberProviders
{
    /** @var list<EnrollmentNumberProvider>|null */
    private ?array $providers = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<EnrollmentNumberProvider> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, EnrollmentNumberProvider::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $provider = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($provider instanceof EnrollmentNumberProvider) {
                    $found[spl_object_id($provider)] = $provider;
                }
            }
        }

        return $this->providers = array_values($found);
    }
}
