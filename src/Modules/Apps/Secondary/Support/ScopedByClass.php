<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Support;

use Scholaris\Core\Http\HttpException;

final class ScopedByClass
{
    /** @param array<string, ?string> $row */
    public function assertReadable(Perimeter $perimeter, array $row): void
    {
        if (!$perimeter->coversClass($row['class_id'] ?? null)) {
            throw HttpException::notFound();
        }
    }

    /** @return array{0: string, 1: list<string>} */
    public function sqlFilter(Perimeter $perimeter, string $column = 'class_id'): array
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('Colonne invalide.');
        }
        if ($perimeter->wholeEstablishment) {
            return ['1 = 1', []];
        }
        if ($perimeter->classIds === []) {
            return ['1 = 0', []];
        }

        return [$column . ' IN (' . implode(', ', array_fill(0, count($perimeter->classIds), '?')) . ')', $perimeter->classIds];
    }
}
