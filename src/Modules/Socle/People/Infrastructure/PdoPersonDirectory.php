<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

final class PdoPersonDirectory implements PersonDirectory
{
    private const COLUMNS = 'id, tenant_id, first_name, last_name, phone, email, locale, gender';

    public function __construct(private readonly PdoStore $store)
    {
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->findMany($tenantId, [$personId])[0] ?? null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        $personIds = array_values(array_unique($personIds));
        if ($personIds === []) {
            return [];
        }
        $rows = $this->store->rows(
            sprintf('SELECT %s FROM people WHERE tenant_id = ? AND id IN (%s) ORDER BY last_name, first_name', self::COLUMNS, implode(', ', array_fill(0, count($personIds), '?'))),
            [$tenantId, ...$personIds],
        );

        return array_map($this->summary(...), $rows);
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        $rows = $this->store->rows(
            "SELECT related_person_id FROM person_relations WHERE tenant_id = ? AND person_id = ? AND kind IN ('financial_guarantor', 'guardian')",
            [$tenantId, $learnerId],
        );

        return $this->findMany($tenantId, array_map(static fn (array $r): string => (string) $r['related_person_id'], $rows));
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        $rows = $this->store->rows(
            "SELECT person_id FROM person_relations WHERE tenant_id = ? AND related_person_id = ? AND kind = 'financial_guarantor'",
            [$tenantId, $guarantorId],
        );

        return $this->findMany($tenantId, array_map(static fn (array $r): string => (string) $r['person_id'], $rows));
    }

    /** @param array<string, mixed> $row */
    private function summary(array $row): PersonSummary
    {
        return new PersonSummary(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            $row['phone'] === null ? null : (string) $row['phone'],
            $row['email'] === null ? null : (string) $row['email'],
            (string) $row['locale'],
            isset($row['gender']) && $row['gender'] !== '' ? (string) $row['gender'] : null,
        );
    }
}
