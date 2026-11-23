<?php

declare(strict_types=1);

namespace Tests\Support\Scheduling;

use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/** Doublure de PersonDirectory : personnes et liens garant -> apprenants en mémoire. */
final class InMemoryPeople implements PersonDirectory
{
    /** @var array<string, PersonSummary> */
    private array $people = [];

    /** @var array<string, list<string>> garant => apprenants (clé « client|garant ») */
    private array $learners = [];

    public function add(string $tenantId, string $id, string $firstName, string $lastName, ?string $phone = null): void
    {
        $this->people[$tenantId . '|' . $id] = new PersonSummary($id, $tenantId, $firstName, $lastName, $phone);
    }

    public function link(string $tenantId, string $guarantorId, string $learnerId): void
    {
        $this->learners[$tenantId . '|' . $guarantorId][] = $learnerId;
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->people[$tenantId . '|' . $personId] ?? null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?PersonSummary => $this->find($tenantId, $id), $personIds)));
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        $out = [];
        foreach ($this->learners as $key => $learnerIds) {
            [$tenant, $guarantor] = explode('|', $key, 2);
            if ($tenant === $tenantId && in_array($learnerId, $learnerIds, true) && ($person = $this->find($tenantId, $guarantor)) !== null) {
                $out[] = $person;
            }
        }

        return $out;
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return $this->findMany($tenantId, $this->learners[$tenantId . '|' . $guarantorId] ?? []);
    }
}
