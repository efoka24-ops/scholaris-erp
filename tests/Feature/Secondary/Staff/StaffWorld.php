<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Staff;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;
use Scholaris\Modules\Socle\Staff\Contracts\StaffDirectory;
use Scholaris\Modules\Socle\Staff\Contracts\StaffInfo;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/** Doubles de contrats socle avec des personnes et des fiches de personnel fictives. */
final class StaffWorld implements StaffDirectory, TeacherWorkload, SecondaryDocuments
{
    /** @var array<string, StaffInfo> */
    private array $members = [];

    /** @var array<string, list<string>> */
    private array $establishments = [];

    /** @var array<string, PersonSummary> */
    private array $people = [];

    /** @var list<array{kind: string, person: string, values: array<string, mixed>}> documents émis (DOC-30) */
    public array $issued = [];

    public function add(string $tenantId, string $establishmentId, string $personId, string $staffId, string $name, string $kind = 'permanent', string $status = 'active'): void
    {
        $parts = explode(' ', $name, 2);
        $this->people[$personId] = new PersonSummary($personId, $tenantId, $parts[0], $parts[1] ?? 'Test');
        $this->members[$tenantId . ':' . $personId] = new StaffInfo($personId, $tenantId, $kind, 'Grade fictif', $status, $staffId);
        $this->establishments[$tenantId . ':' . $establishmentId][] = $personId;
    }

    public function find(string $tenantId, string $personId): ?StaffInfo
    {
        return $this->members[$tenantId . ':' . $personId] ?? null;
    }

    public function findPerson(string $tenantId, string $personId): ?PersonSummary
    {
        $person = $this->people[$personId] ?? null;

        return $person !== null && $person->tenantId === $tenantId ? $person : null;
    }

    public function teacherIdsIn(string $tenantId, string $establishmentId): array
    {
        return array_values(array_unique($this->establishments[$tenantId . ':' . $establishmentId] ?? []));
    }


    public function assignmentsOf(string $tenantId, string $personId): array
    {
        return [];
    }

    public function hoursOf(string $tenantId, string $establishmentId, string $personId, string $month): array
    {
        return ['month' => $month, 'declared_minutes' => 60, 'validated_minutes' => 120, 'rejected_minutes' => 0];
    }

    public function issue(string $tenantId, string $establishmentId, string $kind, string $personId, array $values, string $issuedBy, ?string $locale = null): IssuedDocument
    {
        $this->issued[] = ['kind' => $kind, 'person' => $personId, 'values' => $values];

        return new IssuedDocument('01J00000000000000000000000', 'SCH-2026-W-000001', 'fictif000000000000000000000000', IssuedDocument::VALID, 'sec_doc30_payroll_payslip', $personId);
    }

    public function revoke(string $tenantId, string $documentId, string $reason, string $revokedBy): void
    {
    }
}

/** PersonDirectory séparé : les deux contrats Socle ont des signatures find() incompatibles. */
final class StaffPeople implements PersonDirectory
{
    public function __construct(private readonly StaffWorld $world)
    {
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->world->findPerson($tenantId, $personId);
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        $people = [];
        foreach ($personIds as $personId) {
            $person = $this->find($tenantId, $personId);
            if ($person !== null) {
                $people[] = $person;
            }
        }

        return $people;
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return [];
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return [];
    }
}
