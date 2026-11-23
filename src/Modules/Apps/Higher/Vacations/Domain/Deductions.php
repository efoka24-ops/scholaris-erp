<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Domain;

/**
 * Retenues sur le brut des vacations (RG-ENS-2/3 non précisées, Q21 : IRPP, CAC…) : paramètre `higher.vacation.deductions`, liste de
 * {code, label, percent}. Aucune retenue par défaut (« à confirmer »). Montants entiers XAF arrondis au franc.
 */
final class Deductions
{
    private function __construct()
    {
    }

    /**
     * @return array{lines: list<array{code: string, label: string, percent: float, amount: int}>, total: int}
     */
    public static function apply(mixed $setting, int $gross): array
    {
        $lines = [];
        foreach (is_array($setting) ? $setting : [] as $entry) {
            if (!is_array($entry) || !isset($entry['code'], $entry['percent']) || !is_numeric($entry['percent'])) {
                continue;
            }
            $percent = (float) $entry['percent'];
            if ($percent <= 0.0 || $percent > 100.0) {
                continue;
            }
            $lines[] = [
                'code' => (string) $entry['code'],
                'label' => (string) ($entry['label'] ?? $entry['code']),
                'percent' => $percent,
                'amount' => (int) round($gross * $percent / 100),
            ];
        }
        $total = min($gross, array_sum(array_column($lines, 'amount')));

        return ['lines' => $lines, 'total' => $total];
    }
}
