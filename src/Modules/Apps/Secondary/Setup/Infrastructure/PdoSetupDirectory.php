<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Infrastructure;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfileInfo;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientBook;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\CoefficientLine;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Scholaris\Modules\Apps\Secondary\Setup\Domain\RuleKeys;
use Scholaris\Modules\Apps\Secondary\Setup\Domain\SubjectRoles;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;

/** Lecture des données de paramétrage offertes aux autres sous-modules Secondary (classes, coefficients, règlement intérieur). */
final class PdoSetupDirectory implements ClassProfiles, CoefficientBook, SchoolRules
{
    public function __construct(private readonly Store $store, private readonly SettingsReader $settings)
    {
    }

    public function find(string $tenantId, string $establishmentId, string $structureId): ?ClassProfileInfo
    {
        $row = $this->store->select('se_class_profiles', $tenantId, $establishmentId, ['structure_id' => $structureId], 'academic_year_id DESC', 1)[0] ?? null;

        return $row === null ? null : self::info($row);
    }

    public function forYear(string $tenantId, string $establishmentId, string $academicYearId): array
    {
        $rows = $this->store->select('se_class_profiles', $tenantId, $establishmentId, ['academic_year_id' => $academicYearId], 'level_code, label', 2000);

        return array_map(self::info(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function level(string $tenantId, string $establishmentId, string $levelId): ?array
    {
        return $this->store->find('se_levels', $tenantId, $establishmentId, $levelId);
    }

    public function forLevel(string $tenantId, string $establishmentId, string $academicYearId, string $levelCode, ?string $streamId = null): array
    {
        $subjects = [];
        foreach ($this->store->select('se_subjects', $tenantId, $establishmentId, [], 'id', 1000) as $row) {
            $subjects[(string) $row['id']] = $row;
        }
        $byLevel = $this->store->select('se_subject_coefficients', $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'level_code' => $levelCode, 'stream_id' => null, 'superseded_at' => null], 'se_subject_id', 1000);
        $byStream = $streamId === null ? [] : $this->store->select('se_subject_coefficients', $tenantId, $establishmentId, ['academic_year_id' => $academicYearId, 'level_code' => $levelCode, 'stream_id' => $streamId, 'superseded_at' => null], 'se_subject_id', 1000);
        $lines = [];
        foreach ([...$byLevel, ...$byStream] as $row) {
            $subject = $subjects[(string) $row['se_subject_id']] ?? null;
            if ($subject === null || (int) $subject['active'] !== 1) {
                continue;
            }
            $role = (string) $row['subject_role'];
            $lines[(string) $row['se_subject_id']] = new CoefficientLine(
                (string) $row['id'],
                (string) $row['subject_id'],
                (string) $row['se_subject_id'],
                (string) $subject['code'],
                (string) $subject['label'],
                (string) $row['level_code'],
                is_string($row['stream_id']) ? $row['stream_id'] : null,
                (float) $row['coefficient'],
                (float) $row['weekly_hours'],
                $role,
                SubjectRoles::countsInAverage($role),
                (int) $row['locked'] === 1,
                (string) $row['origin'],
                (int) $row['version_no'],
                (string) $row['valid_from'],
            );
        }

        return array_values($lines);
    }

    public function value(string $tenantId, string $establishmentId, string $key, mixed $default = null): mixed
    {
        if (!RuleKeys::exists($key)) {
            return $default;
        }
        $row = $this->store->select('se_school_rules', $tenantId, $establishmentId, ['rule_key' => $key, 'superseded_at' => null], 'version_no DESC', 1)[0] ?? null;
        if ($row !== null) {
            return json_decode((string) $row['value_json'], true);
        }

        return $this->settings->value($tenantId, $establishmentId, RuleKeys::settingKey($key), $default);
    }

    /** @param array<string, mixed> $row */
    private static function info(array $row): ClassProfileInfo
    {
        return new ClassProfileInfo(
            (string) $row['id'],
            (string) $row['structure_id'],
            (string) $row['academic_year_id'],
            (string) $row['label'],
            (string) $row['section_code'],
            (string) $row['teaching_kind'],
            (string) $row['cycle_code'],
            (string) $row['level_id'],
            (string) $row['level_code'],
            is_string($row['stream_id']) ? $row['stream_id'] : null,
            is_string($row['exam_code']) ? $row['exam_code'] : null,
            $row['capacity'] === null ? null : (int) $row['capacity'],
        );
    }
}
