<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Infrastructure;

use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentInfo;

/** Contrat StudentDirectory sur hi_students et hi_enrolments. */
final class PdoStudentDirectory implements StudentDirectory
{
    public function __construct(private readonly EnrolmentStore $store)
    {
    }

    public function student(string $tenantId, string $establishmentId, string $personId): ?StudentInfo
    {
        $row = $this->store->select('hi_students', $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;

        return $row === null ? null : self::toStudent($row);
    }

    public function byMatricule(string $tenantId, string $establishmentId, string $matricule): ?StudentInfo
    {
        $row = $this->store->select('hi_students', $tenantId, $establishmentId, ['matricule' => $matricule], 'id', 1)[0] ?? null;

        return $row === null ? null : self::toStudent($row);
    }

    public function enrolmentsOf(string $tenantId, string $establishmentId, string $personId): array
    {
        $matricule = $this->student($tenantId, $establishmentId, $personId)?->matricule;

        return array_map(
            static fn (array $row): EnrolmentInfo => self::toEnrolment($row, $matricule),
            $this->store->select('hi_enrolments', $tenantId, $establishmentId, ['person_id' => $personId], 'academic_year_id DESC, created_at DESC', 500),
        );
    }

    public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo
    {
        $row = $this->store->find('hi_enrolments', $tenantId, $establishmentId, $enrolmentId);

        return $row === null ? null : self::toEnrolment($row, $this->student($tenantId, $establishmentId, (string) $row['person_id'])?->matricule);
    }

    public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array
    {
        $rows = $this->store->select('hi_enrolments', $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'person_id', 100000, '(level_id = ? OR group_id = ?) AND status IN (?, ?)', [$structureId, $structureId, 'enrolled', 'financial_hold']);
        $matricules = [];
        foreach ($rows as $row) {
            $matricules[(string) $row['person_id']] ??= $this->student($tenantId, $establishmentId, (string) $row['person_id'])?->matricule;
        }

        return array_map(static fn (array $row): EnrolmentInfo => self::toEnrolment($row, $matricules[(string) $row['person_id']] ?? null), $rows);
    }

    public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool
    {
        return $this->student($tenantId, $establishmentId, $personId)->sharesWithGuarantor ?? false;
    }

    /** @param array<string, mixed> $row */
    private static function toStudent(array $row): StudentInfo
    {
        return new StudentInfo(
            (string) $row['id'],
            (string) $row['person_id'],
            isset($row['matricule']) ? (string) $row['matricule'] : null,
            isset($row['photo_ref']) ? (string) $row['photo_ref'] : null,
            isset($row['guarantor_person_id']) ? (string) $row['guarantor_person_id'] : null,
            (int) $row['share_with_guarantor'] === 1,
        );
    }

    /** @param array<string, mixed> $row */
    private static function toEnrolment(array $row, ?string $matricule): EnrolmentInfo
    {
        return new EnrolmentInfo(
            (string) $row['id'],
            (string) $row['person_id'],
            $matricule,
            (string) $row['academic_year_id'],
            (string) $row['school_id'],
            (string) $row['program_id'],
            (string) $row['level_id'],
            isset($row['group_id']) ? (string) $row['group_id'] : null,
            (string) $row['study_mode'],
            (string) $row['status'],
            isset($row['curriculum_id']) ? (string) $row['curriculum_id'] : null,
            (int) $row['attempt_number'],
            isset($row['enrollment_id']) ? (string) $row['enrollment_id'] : null,
        );
    }
}
