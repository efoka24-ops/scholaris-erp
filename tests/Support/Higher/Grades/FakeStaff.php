<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Grades;

use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;

/** Doublure de l'annuaire du personnel (Staff) adossée à GradesFakes. */
final class FakeStaff implements StaffDirectory
{
    public function __construct(private readonly GradesFakes $fakes)
    {
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->fakes->staff[$tenantId . ':' . $personId] ?? null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        $ids = [];
        foreach ($this->fakes->staff as $info) {
            if ($info->tenantId === $tenantId) {
                $ids[] = $info->personId;
            }
        }

        return $ids;
    }
}
