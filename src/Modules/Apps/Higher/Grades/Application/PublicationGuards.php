<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use DI\Container;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\PublicationGuard;

/** Découvre les gardes de publication enregistrées (`higher.grades.publication_guards.*`, Contracts\PublicationGuard). */
final class PublicationGuards
{
    /** @var list<PublicationGuard>|null */
    private ?array $guards = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return list<PublicationGuard> */
    public function all(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }
        $found = [];
        $names = $this->container->getKnownEntryNames();
        sort($names);
        foreach ($names as $name) {
            if (!str_starts_with($name, PublicationGuard::PREFIX)) {
                continue;
            }
            foreach ((array) $this->container->get($name) as $entry) {
                $guard = is_string($entry) ? $this->container->get($entry) : $entry;
                if ($guard instanceof PublicationGuard) {
                    $found[spl_object_id($guard)] = $guard;
                }
            }
        }

        return $this->guards = array_values($found);
    }
}
