<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Domain;

/**
 * État des vacations d'un examen (décret n° 2010/1738/PM du 7 juin 2010) : chaque intervenant d'un centre (fonction, demi-journées,
 * copies corrigées) et l'indemnité des directeurs d'école par candidat. AUCUN montant ici : les taux viennent du paramètre
 * `public_primary.exams.vacation_rates` (half_day, copy, director_per_candidate, per_function {fonction: {half_day?, copy?}}).
 * Montants entiers en FCFA.
 */
final class VacationStatement
{
    private function __construct()
    {
    }

    /**
     * @param list<array{id: string, centre_id: string, full_name: string, function_code: string, half_days: int, copies_marked: int}> $officials
     * @param array<string, mixed> $rates
     * @param array<string, int> $candidatesBySchool établissement => candidats présentés
     * @return array{lines: list<array<string, mixed>>, directors: list<array{establishment_id: string, candidates: int, amount: int}>, total_officials: int, total_directors: int, total: int}
     */
    public static function compute(array $officials, array $rates, array $candidatesBySchool): array
    {
        $lines = [];
        $totalOfficials = 0;
        foreach ($officials as $official) {
            $own = is_array($rates['per_function'][$official['function_code']] ?? null) ? $rates['per_function'][$official['function_code']] : [];
            $halfDayRate = self::rate($own['half_day'] ?? ($rates['half_day'] ?? 0));
            $copyRate = self::rate($own['copy'] ?? ($rates['copy'] ?? 0));
            $amount = $official['half_days'] * $halfDayRate + $official['copies_marked'] * $copyRate;
            $totalOfficials += $amount;
            $lines[] = $official + ['half_day_rate' => $halfDayRate, 'copy_rate' => $copyRate, 'amount' => $amount];
        }
        $directorRate = self::rate($rates['director_per_candidate'] ?? 0);
        $directors = [];
        $totalDirectors = 0;
        ksort($candidatesBySchool);
        foreach ($candidatesBySchool as $establishmentId => $candidates) {
            $amount = $candidates * $directorRate;
            $totalDirectors += $amount;
            $directors[] = ['establishment_id' => (string) $establishmentId, 'candidates' => $candidates, 'amount' => $amount];
        }

        return ['lines' => $lines, 'directors' => $directors, 'total_officials' => $totalOfficials, 'total_directors' => $totalDirectors, 'total' => $totalOfficials + $totalDirectors];
    }

    private static function rate(mixed $value): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : 0;
    }
}
