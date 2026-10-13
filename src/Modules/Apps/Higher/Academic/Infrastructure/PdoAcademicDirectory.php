<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Infrastructure;

use Scholaris\Modules\Apps\Higher\Academic\Contracts\AcademicDirectory;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\GroupInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\LevelInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\ProgramInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\SchoolInfo;
use Scholaris\Modules\Apps\Higher\Academic\Contracts\TutelageInfo;

/** Lecture du paramétrage académique dans les tables hi_* d'Academic. */
final class PdoAcademicDirectory implements AcademicDirectory
{
    public function __construct(private readonly Store $store)
    {
    }

    public function school(string $tenantId, string $establishmentId, string $schoolId): ?SchoolInfo
    {
        $row = $this->store->find('hi_schools', $tenantId, $establishmentId, $schoolId);

        return $row === null ? null : self::schoolInfo($row);
    }

    public function program(string $tenantId, string $establishmentId, string $programId): ?ProgramInfo
    {
        $row = $this->store->find('hi_programs', $tenantId, $establishmentId, $programId);
        if ($row === null) {
            return null;
        }
        $school = $this->store->find('hi_schools', $tenantId, $establishmentId, (string) $row['school_id']);

        return self::programInfo($row, (string) ($school['code'] ?? ''));
    }

    public function level(string $tenantId, string $establishmentId, string $levelId): ?LevelInfo
    {
        $row = $this->store->find('hi_levels', $tenantId, $establishmentId, $levelId);
        if ($row === null) {
            return null;
        }
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, (string) $row['program_id']);

        return self::levelInfo($row, (int) ($program['duration_years'] ?? 0));
    }

    public function levelsOf(string $tenantId, string $establishmentId, string $programId): array
    {
        $program = $this->store->find('hi_programs', $tenantId, $establishmentId, $programId);
        if ($program === null) {
            return [];
        }

        return array_map(
            static fn (array $row): LevelInfo => self::levelInfo($row, (int) $program['duration_years']),
            $this->store->select('hi_levels', $tenantId, $establishmentId, ['program_id' => $programId], 'ordinal'),
        );
    }

    public function group(string $tenantId, string $establishmentId, string $groupId): ?GroupInfo
    {
        $row = $this->store->find('hi_groups', $tenantId, $establishmentId, $groupId);

        return $row === null ? null : self::groupInfo($row);
    }

    public function groupsOf(string $tenantId, string $establishmentId, string $levelId): array
    {
        return array_map(self::groupInfo(...), $this->store->select('hi_groups', $tenantId, $establishmentId, ['level_id' => $levelId], 'code'));
    }

    public function tutelageFor(string $tenantId, string $establishmentId, string $programId, string $academicYearId): ?TutelageInfo
    {
        $row = $this->store->select('hi_tutelages', $tenantId, $establishmentId, ['program_id' => $programId, 'academic_year_id' => $academicYearId], 'id', 1)[0] ?? null;

        return $row === null ? null : new TutelageInfo(
            (string) $row['id'],
            (string) $row['program_id'],
            (string) $row['academic_year_id'],
            (string) $row['university_name'],
            self::nullable($row['convention_reference']),
            self::nullable($row['convention_ends_on']),
            self::nullable($row['approved_programme_ref']),
        );
    }

    /** @param array<string, mixed> $row */
    public static function schoolInfo(array $row): SchoolInfo
    {
        return new SchoolInfo((string) $row['id'], (string) $row['code'], (string) $row['name'], self::nullable($row['director_person_id']));
    }

    /** @param array<string, mixed> $row */
    public static function programInfo(array $row, string $schoolCode): ProgramInfo
    {
        return new ProgramInfo(
            (string) $row['id'],
            (string) $row['school_id'],
            $schoolCode,
            (string) $row['department_id'],
            self::nullable($row['parent_program_id']),
            (string) $row['code'],
            (string) $row['name'],
            (string) $row['regime'],
            (string) $row['teaching_language'],
            (string) $row['study_mode'],
            (int) $row['duration_years'],
            (string) $row['status'],
        );
    }

    /** @param array<string, mixed> $row */
    private static function levelInfo(array $row, int $duration): LevelInfo
    {
        return new LevelInfo((string) $row['id'], (string) $row['program_id'], (int) $row['ordinal'], (string) $row['code'], (string) $row['name'], (int) $row['ordinal'] === $duration);
    }

    /** @param array<string, mixed> $row */
    private static function groupInfo(array $row): GroupInfo
    {
        return new GroupInfo(
            (string) $row['id'],
            (string) $row['level_id'],
            (string) $row['program_id'],
            (string) $row['code'],
            (string) $row['name'],
            $row['capacity'] === null ? null : (int) $row['capacity'],
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
