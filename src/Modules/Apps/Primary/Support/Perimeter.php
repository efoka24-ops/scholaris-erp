<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Support;

final class Perimeter
{
    /** @param list<string> $classIds */
    public function __construct(public readonly string $tenantId, public readonly string $establishmentId, public readonly bool $wholeEstablishment, public readonly array $classIds = [])
    {
    }

    public function coversClass(?string $classId): bool
    {
        return $classId !== null && ($this->wholeEstablishment || in_array($classId, $this->classIds, true));
    }
}
