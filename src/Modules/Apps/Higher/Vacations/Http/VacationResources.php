<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Http;

/** Représentation JSON d'un état et de ses lignes : entiers typés, JSON décodé, HETD aussi en décimal (43,5 = 43.5). */
final class VacationResources
{
    private const INTS = [
        'coef_cm', 'coef_td', 'coef_tp', 'coef_other', 'cm_minutes', 'td_minutes', 'tp_minutes', 'other_minutes', 'cm_hetd_milli',
        'td_hetd_milli', 'tp_hetd_milli', 'other_hetd_milli', 'hetd_milli', 'hourly_rate', 'gross_amount', 'deductions_amount',
        'net_amount', 'excluded_minutes', 'pending_sessions', 'minutes',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function statement(array $row): array
    {
        unset($row['tenant_id'], $row['establishment_id']);
        $out = self::typed($row);
        $out['hetd'] = ((int) ($row['hetd_milli'] ?? 0)) / 1000;
        $out['deductions'] = self::decode($row['deductions'] ?? null);
        $out['hold_reasons'] = self::decode($row['hold_reasons'] ?? null);
        if (isset($row['lines']) && is_array($row['lines'])) {
            $out['lines'] = array_map(self::line(...), array_values($row['lines']));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function line(array $row): array
    {
        unset($row['tenant_id'], $row['establishment_id']);
        $out = self::typed($row);
        $out['hetd_milli'] = (int) ($row['hetd_milli'] ?? 0);
        $out['payable'] = (int) ($row['payable'] ?? 0) === 1;

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function typed(array $row): array
    {
        foreach (self::INTS as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /** @return list<mixed> */
    private static function decode(mixed $json): array
    {
        $value = is_string($json) && $json !== '' ? json_decode($json, true) : [];

        return is_array($value) ? array_values($value) : [];
    }
}
