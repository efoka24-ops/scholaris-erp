<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Internships\Application;

use Scholaris\Modules\Apps\Higher\Internships\Domain\Grade;

/** Représentations JSON des entreprises, conventions, suivis et soutenances (champs stables, booléens et notes typés). */
final class InternshipResources
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function company(array $row): array
    {
        $out = self::strip($row);
        $out['active'] = (int) $row['active'] === 1;

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function internship(array $row): array
    {
        $out = self::strip($row);
        $out['signatures'] = [
            'student' => $row['student_signed_on'] ?? null,
            'company' => $row['company_signed_on'] ?? null,
            'school' => $row['school_signed_on'] ?? null,
        ];

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function followup(array $row): array
    {
        $out = self::strip($row);
        unset($out['score_hundredths']);
        $out['score'] = Grade::fromHundredths($row['score_hundredths'] ?? null);

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $jury
     * @return array<string, mixed>
     */
    public static function defense(array $row, array $jury = []): array
    {
        $out = self::strip($row);
        unset($out['grade_hundredths']);
        $out['grade'] = Grade::fromHundredths($row['grade_hundredths'] ?? null);
        $out['jury'] = array_map(static fn (array $m): array => [
            'person_id' => (string) $m['person_id'], 'display_name' => (string) $m['display_name'], 'jury_role' => (string) $m['jury_role'],
        ], $jury);

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function strip(array $row): array
    {
        unset($row['tenant_id'], $row['establishment_id']);

        return $row;
    }
}
