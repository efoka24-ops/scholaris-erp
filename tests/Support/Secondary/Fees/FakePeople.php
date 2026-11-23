<?php

declare(strict_types=1);

namespace Tests\Support\Secondary\Fees;

use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/** Doublure des personnes (identité, garants, date de naissance) ; données fictives, cloisonnées par client. */
final class FakePeople implements PersonDirectory, PersonDemographics
{
    /** @var array<string, PersonSummary> */
    private array $people = [];

    /** @var array<string, list<string>> */
    private array $guarantors = [];

    /** @var array<string, string> */
    private array $births = [];

    public function person(string $tenantId, string $id, string $first, string $last, ?string $birth = null): void
    {
        $this->people[$tenantId . '|' . $id] = new PersonSummary($id, $tenantId, $first, $last);
        if ($birth !== null) {
            $this->births[$tenantId . '|' . $id] = $birth;
        }
    }

    public function guarantor(string $tenantId, string $learnerId, string $guarantorId): void
    {
        $this->guarantors[$tenantId . '|' . $learnerId][] = $guarantorId;
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->people[$tenantId . '|' . $personId] ?? null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?PersonSummary => $this->people[$tenantId . '|' . $id] ?? null, $personIds)));
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return $this->findMany($tenantId, $this->guarantors[$tenantId . '|' . $learnerId] ?? []);
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        $out = [];
        foreach ($this->guarantors as $key => $ids) {
            [$tenant, $learner] = explode('|', $key, 2);
            if ($tenant === $tenantId && in_array($guarantorId, $ids, true) && isset($this->people[$key])) {
                $out[] = $this->people[$key];
            }
        }

        return $out;
    }

    public function ofPersons(string $tenantId, array $personIds): array
    {
        $out = [];
        foreach ($personIds as $id) {
            if (isset($this->people[$tenantId . '|' . $id])) {
                $out[$id] = ['gender' => null, 'birth_date' => $this->births[$tenantId . '|' . $id] ?? null];
            }
        }

        return $out;
    }
}
