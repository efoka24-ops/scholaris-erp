<?php

declare(strict_types=1);

namespace Tests\Support\Assessment;

use Scholaris\Modules\Socle\People\Contracts\TeachingScope;

/** Doublure de TeachingScope : élèves par enseignant (personne). */
final class FixedTeaching implements TeachingScope
{
    /** @var array<string, list<string>> */
    public array $classes = [];

    public function learnerIdsOf(string $tenantId, string $teacherPersonId): array
    {
        return $this->classes[$teacherPersonId] ?? [];
    }
}
