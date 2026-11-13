<?php

declare(strict_types=1);

namespace Tests\Support\People;

use Scholaris\Modules\Socle\People\Contracts\TeachingScope;

final class FixedTeaching implements TeachingScope
{
    /** @var array<string, list<string>> */
    public array $classes = [];

    public function learnerIdsOf(string $tenantId, string $teacherPersonId): array
    {
        return $this->classes[$teacherPersonId] ?? [];
    }
}
