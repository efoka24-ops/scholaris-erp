<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Http;

/** Ressources publiques des présences et de la discipline : client et établissement jamais rendus, entiers et JSON décodés. */
final class AttendanceResources
{
    private const HIDDEN = ['tenant_id', 'establishment_id'];
    private const INTS = ['threshold_percent', 'barred_count', 'rate_permille', 'enrolment_excluded'];
    private const JSON = ['entries', 'members'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function of(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (in_array($key, self::HIDDEN, true)) {
                continue;
            }
            if ($value !== null && in_array($key, self::INTS, true)) {
                $value = (int) $value;
            } elseif (in_array($key, self::JSON, true)) {
                $decoded = json_decode((string) ($value ?? '[]'), true);
                $value = is_array($decoded) ? $decoded : [];
            }
            $out[(string) $key] = $value;
        }
        if (array_key_exists('enrolment_excluded', $out)) {
            $out['enrolment_excluded'] = $out['enrolment_excluded'] === 1;
            $out['exclusion_pending'] = ($row['decision'] ?? null) === 'permanent_exclusion' && ($row['status'] ?? null) === 'signed' && !$out['enrolment_excluded'];
        }

        return $out;
    }
}
