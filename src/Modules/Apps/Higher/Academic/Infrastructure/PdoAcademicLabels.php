<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicLabels;
use Scholaris\Modules\Apps\Higher\Support\AggregateReader;

final class PdoAcademicLabels implements AcademicLabels
{
    private const TABLES = [
        self::SCHOOL => 'hi_schools', self::DEPARTMENT => 'hi_departments', self::PROGRAM => 'hi_programs',
        self::LEVEL => 'hi_levels', self::GROUP => 'hi_groups',
    ];
    private const CHUNK = 400;

    public function __construct(private readonly AggregateReader $reader)
    {
    }

    public function names(string $tenantId, string $establishmentId, string $kind, array $ids): array
    {
        $table = self::TABLES[$kind];
        $labels = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $rows = $this->reader->fetch(
                'SELECT id, code, name FROM ' . $table . ' WHERE tenant_id = ? AND establishment_id = ? AND id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$tenantId, $establishmentId, ...$chunk],
            );
            foreach ($rows as $row) {
                $labels[(string) $row['id']] = (string) $row['code'] . ' - ' . (string) $row['name'];
            }
        }

        return $labels;
    }

    public function schools(string $tenantId, string $establishmentId): array
    {
        $labels = [];
        foreach ($this->reader->fetch('SELECT id, code, name FROM hi_schools WHERE tenant_id = ? AND establishment_id = ? ORDER BY code', [$tenantId, $establishmentId]) as $row) {
            $labels[(string) $row['id']] = (string) $row['code'] . ' - ' . (string) $row['name'];
        }

        return $labels;
    }
}
