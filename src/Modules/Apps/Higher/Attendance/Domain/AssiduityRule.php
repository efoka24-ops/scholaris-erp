<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Domain;

/**
 * Deux indicateurs distincts (T426, decisions.md E1) :
 *  - RG-PRE-1, par EC : taux d'absences NON justifiées = absences non justifiées ÷ séances tenues de l'EC ; strictement au-dessus du
 *    seuil (30 %) : non autorisé à composer cet EC ; à partir du seuil d'alerte (25 %) : alerte NOT-07. 4 sur 12 (33,3 %) : non
 *    autorisé ; 3 sur 12 (25 %) : alerte seulement.
 *  - Examens nationaux BTS/HND : taux de PRÉSENCE = (séances tenues − absences non justifiées) ÷ séances tenues sur l'année ;
 *    éligible à partir de 70 %.
 * Comparaisons en entiers (pas d'arrondi qui ferait basculer un cas limite).
 */
final class AssiduityRule
{
    public const OK = 'ok';
    public const ALERT = 'alert';
    public const BARRED = 'barred';
    public const LIFTED = 'lifted';

    private function __construct()
    {
    }

    /** @return array{sessions: int, unjustified: int, rate_percent: float, status: string} */
    public static function forElement(int $sessions, int $unjustified, float $maxPercent = 30.0, float $alertPercent = 25.0, bool $lifted = false): array
    {
        $status = self::OK;
        if ($sessions > 0) {
            if ($unjustified * 100 > $maxPercent * $sessions) {
                $status = $lifted ? self::LIFTED : self::BARRED;
            } elseif ($unjustified * 100 >= $alertPercent * $sessions && $unjustified > 0) {
                $status = self::ALERT;
            }
        }

        return ['sessions' => $sessions, 'unjustified' => $unjustified, 'rate_percent' => self::rate($unjustified, $sessions), 'status' => $status];
    }

    /** @return array{sessions: int, unjustified: int, presence_percent: float, threshold_percent: float, eligible: bool} */
    public static function presence(int $sessions, int $unjustified, float $minPercent = 70.0): array
    {
        $present = max(0, $sessions - $unjustified);

        return [
            'sessions' => $sessions, 'unjustified' => $unjustified,
            'presence_percent' => $sessions === 0 ? 100.0 : self::rate($present, $sessions), 'threshold_percent' => $minPercent,
            'eligible' => $sessions === 0 || $present * 100 >= $minPercent * $sessions,
        ];
    }

    private static function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(100 * $part / $whole, 1);
    }
}
