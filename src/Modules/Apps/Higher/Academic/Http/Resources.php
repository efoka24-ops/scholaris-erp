<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Http;

use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationInfo;

/** Ressources publiques d'Academic : liste blanche de champs (aucune colonne technique ni client). */
final class Resources
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function school(array $row): array
    {
        return self::pick($row, ['id', 'code', 'name', 'director_person_id', 'created_at', 'updated_at']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function department(array $row): array
    {
        return self::pick($row, ['id', 'school_id', 'code', 'name', 'head_person_id', 'created_at', 'updated_at']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function program(array $row): array
    {
        $data = self::pick($row, [
            'id', 'school_id', 'department_id', 'parent_program_id', 'code', 'name', 'regime', 'teaching_language', 'study_mode',
            'duration_years', 'status', 'created_at', 'updated_at',
        ]);
        $data['duration_years'] = (int) $data['duration_years'];
        $data['requires_tutelage'] = $row['regime'] === 'lmd';

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function level(array $row): array
    {
        $data = self::pick($row, ['id', 'school_id', 'department_id', 'program_id', 'ordinal', 'code', 'name', 'created_at', 'updated_at']);
        $data['ordinal'] = (int) $data['ordinal'];

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function group(array $row): array
    {
        $data = self::pick($row, ['id', 'school_id', 'department_id', 'program_id', 'level_id', 'code', 'name', 'capacity', 'created_at', 'updated_at']);
        $data['capacity'] = $row['capacity'] === null ? null : (int) $row['capacity'];

        return $data;
    }

    /**
     * Autorisation : rattachement IPES (hi_authorizations) + valeurs du registre du socle, état au jour donné.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function authorization(array $row, ?AuthorizationInfo $info, string $today): array
    {
        $data = self::pick($row, ['id', 'school_id', 'department_id', 'program_id', 'decree_date', 'created_at', 'updated_at']);
        if ($info === null) {
            return $data + ['status' => 'missing'];
        }
        $daysLeft = (int) round((strtotime($info->validTo . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);

        return $data + [
            'decree_number' => $info->reference,
            'authority' => $info->authority,
            'valid_from' => $info->validFrom,
            'valid_to' => $info->validTo,
            'level_ids' => $info->coveredLevels,
            'document_id' => $info->documentId,
            'status' => match (true) {
                $today < $info->validFrom => 'upcoming',
                $today > $info->validTo => 'expired',
                default => 'valid',
            },
            'days_left' => $daysLeft,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function tutelage(array $row): array
    {
        $data = self::pick($row, [
            'id', 'school_id', 'department_id', 'program_id', 'academic_year_id', 'university_name', 'convention_reference',
            'convention_document_id', 'convention_starts_on', 'convention_ends_on', 'approved_programme_ref', 'created_at', 'updated_at',
        ]);
        $contacts = json_decode((string) $row['contacts'], true);
        $data['contacts'] = is_array($contacts) ? $contacts : [];

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function regulation(array $row): array
    {
        $data = self::pick($row, ['id', 'school_id', 'regime', 'academic_year_id', 'version', 'status', 'frozen_at', 'frozen_by', 'created_at', 'updated_at']);
        $data['version'] = (int) $data['version'];
        $parameters = json_decode((string) $row['parameters'], true);
        $data['parameters'] = is_array($parameters) ? $parameters : [];

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function revision(array $row): array
    {
        $data = self::pick($row, ['id', 'regulation_id', 'version', 'reason', 'amended_by', 'created_at']);
        $data['version'] = (int) $data['version'];
        foreach (['previous_parameters', 'new_parameters'] as $field) {
            $decoded = json_decode((string) $row[$field], true);
            $data[$field] = is_array($decoded) ? $decoded : [];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function calendarEntry(array $row): array
    {
        $data = self::pick($row, ['id', 'school_id', 'academic_year_id', 'entry_kind', 'label', 'starts_on', 'ends_on', 'semester_ordinal', 'created_at', 'updated_at']);
        $data['semester_ordinal'] = $row['semester_ordinal'] === null ? null : (int) $row['semester_ordinal'];

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function pick(array $row, array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $row[$field] ?? null;
        }

        return $data;
    }
}
