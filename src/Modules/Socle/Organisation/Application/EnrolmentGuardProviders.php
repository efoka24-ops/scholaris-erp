<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Application;

use DI\Container;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuard;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;

/** Découvre les gardes-fous d'inscription déclarés par les applications (entrées de liste `organisation.enrolment.guards.*`), triés par nom. */
final class EnrolmentGuardProviders
{
    /** @var list<EnrolmentGuard>|null */
    private ?array $guards = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<EnrolmentGuard> */
    public function guards(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, EnrolmentGuards::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $guard = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($guard instanceof EnrolmentGuard) {
                    $found[spl_object_id($guard)] = $guard;
                }
            }
        }

        return $this->guards = array_values($found);
    }
}
