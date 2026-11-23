<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;

final class PdoPersonDemographics implements PersonDemographics
{
    private const CHUNK = 400;

    public function __construct(private readonly PdoStore $store)
    {
    }

    public function ofPersons(string $tenantId, array $personIds): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($personIds)), self::CHUNK) as $chunk) {
            $rows = $this->store->rows(
                'SELECT id, gender, birth_date FROM people WHERE tenant_id = ? AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$tenantId, ...$chunk],
            );
            foreach ($rows as $row) {
                $gender = $row['gender'] ?? null;
                $birth = $row['birth_date'] ?? null;
                $found[(string) $row['id']] = [
                    'gender' => $gender === null || $gender === '' ? null : (string) $gender,
                    'birth_date' => $birth === null || $birth === '' ? null : (string) $birth,
                ];
            }
        }

        return $found;
    }
}
