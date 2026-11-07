<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Grades;

use Scholaris\Modules\Socle\Staff\Contracts\AssignmentDraft;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentInfo;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;

/** Doublure des affectations (Staff, D17/D18) adossée à GradesFakes : lecture seule. */
final class FakeAssignments implements AssignmentWriter
{
    public function __construct(private readonly GradesFakes $fakes)
    {
    }

    public function assign(string $tenantId, string $establishmentId, AssignmentDraft $draft, string $actorId): string
    {
        throw new \LogicException('Non utilisé par Grades.');
    }

    public function change(string $tenantId, string $establishmentId, string $assignmentId, array $changes): void
    {
        throw new \LogicException('Non utilisé par Grades.');
    }

    public function end(string $tenantId, string $establishmentId, string $assignmentId): void
    {
        throw new \LogicException('Non utilisé par Grades.');
    }

    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo
    {
        foreach ($this->fakes->assignments as $assignment) {
            if ($assignment->id === $assignmentId) {
                return $assignment;
            }
        }

        return null;
    }

    public function forElement(string $tenantId, string $establishmentId, string $elementRef, ?string $groupRef = null): array
    {
        return array_values(array_filter($this->fakes->assignments, static fn (AssignmentInfo $a): bool => $a->establishmentId === $establishmentId
            && $a->elementRef === $elementRef && ($groupRef === null || $a->groupRef === $groupRef)));
    }
}
