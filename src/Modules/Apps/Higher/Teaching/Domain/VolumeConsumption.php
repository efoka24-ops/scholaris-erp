<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Domain;

/**
 * Consommation des volumes d'un EC par nature de séance (EX-EDT-04) : prévues (volume affecté, ou volume de la maquette s'il est
 * connu), programmées par l'emploi du temps, réalisées (séances tenues), consignées au cahier de textes, restantes = prévues − réalisées
 * (jamais négatives ; le dépassement est donné à part, RG-ENS-4). Minutes entières.
 */
final class VolumeConsumption
{
    public const KINDS = ['cm', 'td', 'tp'];

    private function __construct()
    {
    }

    /**
     * @param array<string, int> $planned minutes par nature
     * @param array<string, int> $scheduled
     * @param array<string, int> $held
     * @param array<string, int> $logged
     * @return array{by_kind: array<string, array{planned: int, scheduled: int, held: int, logged: int, remaining: int, overrun: int}>,
     *     total: array{planned: int, scheduled: int, held: int, logged: int, remaining: int, overrun: int}}
     */
    public static function compute(array $planned, array $scheduled, array $held, array $logged): array
    {
        $kinds = array_values(array_unique(array_merge(self::KINDS, array_keys($planned), array_keys($held), array_keys($scheduled), array_keys($logged))));
        $rows = [];
        $total = ['planned' => 0, 'scheduled' => 0, 'held' => 0, 'logged' => 0, 'remaining' => 0, 'overrun' => 0];
        foreach ($kinds as $kind) {
            $p = $planned[$kind] ?? 0;
            $h = $held[$kind] ?? 0;
            $row = [
                'planned' => $p, 'scheduled' => $scheduled[$kind] ?? 0, 'held' => $h, 'logged' => $logged[$kind] ?? 0,
                'remaining' => max(0, $p - $h), 'overrun' => max(0, $h - $p),
            ];
            if ($kind === 'other' && array_sum($row) === 0) {
                continue;
            }
            $rows[$kind] = $row;
            foreach ($row as $key => $value) {
                $total[$key] += $value;
            }
        }

        return ['by_kind' => $rows, 'total' => $total];
    }
}
