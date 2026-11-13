<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Staff;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocuments;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentPdfReader;
use Scholaris\Modules\Socle\Documents\Contracts\IssuedDocument;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;
use Scholaris\Modules\Socle\Staff\Contracts\EstablishmentStaffRoster;
use Scholaris\Modules\Socle\Staff\Contracts\StaffMemberInfo;
use Scholaris\Modules\Socle\Staff\Contracts\TeacherWorkload;

/** Doubles des contrats du socle (personnel de l'établissement, heures, émission et PDF de DOC-30), personnes fictives. */
final class StaffWorld implements EstablishmentStaffRoster, TeacherWorkload, SecondaryDocuments, DocumentPdfReader
{
    public const DOCUMENT_ID = '01J00000000000000000000000';

    /** @var array<string, list<StaffMemberInfo>> */
    private array $members = [];

    /** @var array<string, PersonSummary> */
    private array $people = [];

    /** @var list<array{kind: string, person: string, values: array<string, mixed>}> documents émis (DOC-30) */
    public array $issued = [];

    /** @var array<string, string> document émis => établissement */
    private array $documents = [];

    public function add(string $tenantId, string $establishmentId, string $personId, string $staffId, string $name, string $kind = 'permanent', string $status = 'active', bool $teaches = true, ?string $grade = 'Professeur fictif', ?string $category = null): void
    {
        $parts = explode(' ', $name, 2);
        $this->people[$personId] = new PersonSummary($personId, $tenantId, $parts[0], $parts[1] ?? 'Test');
        $this->members[$tenantId . ':' . $establishmentId][] = new StaffMemberInfo($staffId, $personId, $tenantId, $kind, $status, $grade, $category, $teaches);
    }

    public function membersOf(string $tenantId, string $establishmentId): array
    {
        return array_values(array_filter($this->members[$tenantId . ':' . $establishmentId] ?? [], static fn (StaffMemberInfo $m): bool => $m->status === 'active'));
    }

    public function findPerson(string $tenantId, string $personId): ?PersonSummary
    {
        $person = $this->people[$personId] ?? null;

        return $person !== null && $person->tenantId === $tenantId ? $person : null;
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
        $this->documents[$tenantId . ':' . self::DOCUMENT_ID] = $establishmentId;

        return new IssuedDocument(self::DOCUMENT_ID, 'SCH-2026-W-000001', 'fictif000000000000000000000000', IssuedDocument::VALID, 'sec_doc30_payroll_payslip', $personId);
    }

    public function revoke(string $tenantId, string $documentId, string $reason, string $revokedBy): void
    {
    }

    public function pdfOf(string $tenantId, string $establishmentId, string $documentId): ?string
    {
        return ($this->documents[$tenantId . ':' . $documentId] ?? null) === $establishmentId ? '%PDF-1.4 bulletin fictif' : null;
    }
}

/** PersonDirectory séparé (signature de find() différente). */
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
