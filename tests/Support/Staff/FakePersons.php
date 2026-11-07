<?php

declare(strict_types=1);

namespace Tests\Support\Staff;

use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/** Doublure de PersonDirectory : personnes déclarées par client. */
final class FakePersons implements PersonDirectory
{
    /** @var array<string, array<string, PersonSummary>> */
    private array $persons = [];

    public function add(string $tenantId, string $id, string $firstName, string $lastName): void
    {
        $this->persons[$tenantId][$id] = new PersonSummary($id, $tenantId, $firstName, $lastName, '677000000', $id . '@example.test');
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->persons[$tenantId][$personId] ?? null;
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
