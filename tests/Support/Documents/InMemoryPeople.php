<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

final class InMemoryPeople implements PersonDirectory
{
    /** @var array<string, PersonSummary> */
    private array $people = [];

    public function add(string $tenantId, string $id, string $firstName, string $lastName, string $locale = 'fr'): void
    {
        $this->people[$id] = new PersonSummary($id, $tenantId, $firstName, $lastName, null, null, $locale);
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        $person = $this->people[$personId] ?? null;

        return $person !== null && $person->tenantId === $tenantId ? $person : null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?PersonSummary => $this->find($tenantId, $id), $personIds)));
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return [];
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return [];
    }
}
