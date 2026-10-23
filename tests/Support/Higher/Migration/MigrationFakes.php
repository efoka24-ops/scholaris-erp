<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Migration;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentWriter;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentDraft;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentInfo;
use Scholaris\Modules\Socle\Staff\Contracts\AssignmentWriter;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Tests\Support\Billing\InMemoryPeople;

/** Doublures de la reprise : écriture d'élèves et d'inscriptions du socle, fiches du personnel et affectations. Données fictives. */
final class MigrationFakes implements LearnerWriter, EnrollmentWriter, AssignmentWriter
{
    /** @var list<array<string, mixed>> */
    public array $learners = [];

    /** @var array<string, AssignmentInfo> */
    public array $assignments = [];

    public int $closed = 0;

    public readonly FakeStaffDirectory $directory;

    public function __construct(private readonly InMemoryPeople $people)
    {
        $this->directory = new FakeStaffDirectory();
    }

    public function createLearner(string $tenantId, array $draft, array $guardians, ?string $actorId, bool $confirmDifferent = false): array
    {
        $id = 'p-imp-' . (count($this->learners) + 1);
        $this->learners[] = $draft + ['id' => $id, 'tenant' => $tenantId];
        $this->people->add($tenantId, $id, (string) ($draft['first_name'] ?? ''), (string) ($draft['last_name'] ?? ''), isset($draft['phone']) ? (string) $draft['phone'] : null);

        return ['person_id' => $id, 'guardian_ids' => []];
    }

    public function enroll(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, string $date, ?string $actorId): string
    {
        return 'enr-' . $personId;
    }

    public function closeEnrollment(string $tenantId, string $enrollmentId, string $status, string $date): void
    {
        ++$this->closed;
    }

    public function enrol(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, ?string $actorId): string
    {
        return 'enr-' . $personId;
    }

    public function addStaff(string $tenantId, string $personId, string $kind = StaffInfo::VACATAIRE): void
    {
        $this->directory->staff[$tenantId . ':' . $personId] = new StaffInfo($personId, $tenantId, $kind, 'assistant', 'active', 'staff-' . $personId);
        $this->people->add($tenantId, $personId, 'Prof', 'Fictif ' . $personId);
    }

    public function assign(string $tenantId, string $establishmentId, AssignmentDraft $draft, string $actorId): string
    {
        $id = 'sa-' . (count($this->assignments) + 1);
        $this->assignments[$id] = new AssignmentInfo($id, $establishmentId, $draft->staffId, $draft->sessionKind, $draft->plannedMinutes, 0, 'active', $draft->elementRef, $draft->groupRef, $draft->level, $draft->academicYearId, $draft->structureId, $draft->subjectLabel, $draft->startsOn, $draft->endsOn);

        return $id;
    }

    public function change(string $tenantId, string $establishmentId, string $assignmentId, array $changes): void
    {
    }

    public function end(string $tenantId, string $establishmentId, string $assignmentId): void
    {
        unset($this->assignments[$assignmentId]);
    }

    public function find(string $tenantId, string $establishmentId, string $assignmentId): ?AssignmentInfo
    {
        return $this->assignments[$assignmentId] ?? null;
    }

    public function forElement(string $tenantId, string $establishmentId, string $elementRef, ?string $groupRef = null): array
    {
        return [];
    }
}
