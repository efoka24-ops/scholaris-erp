<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Domain;

/**
 * Note d'EC indicative à partir des évaluations publiées (F1 : 0,4 × CC + 0,6 × examen par défaut) : le contrôle continu est la
 * moyenne des CC et TP ramenés sur 20 ; le rattrapage remplace l'examen, le CC est conservé. Effets d'absence (RG-NOT-5) :
 * `zero` compte 0, `excluded` retire la note, `retake` laisse l'épreuve en attente (pas de note). Le calcul officiel (UE,
 * semestre, crédits, décisions) relève de la délibération ; ce calcul sert au portail et aux statistiques.
 */
final class EcMark
{
    private function __construct()
    {
    }

    /**
     * Note sur 20 d'une cellule, null si elle ne compte pas (vide, absence exclue ou épreuve à repasser).
     *
     * @param array<string, string|null> $effects code d'absence => effet
     */
    public static function onTwenty(?float $score, ?string $absence, float $maxScore, array $effects): ?float
    {
        if ($absence !== null) {
            return ($effects[$absence] ?? IpesDefaults::ZERO) === IpesDefaults::ZERO ? 0.0 : null;
        }
        if ($score === null || $maxScore <= 0) {
            return null;
        }

        return round($score * 20 / $maxScore, 2);
    }

    /**
     * Épreuve à repasser (effet `retake`) : la note d'EC reste en attente tant que la nouvelle épreuve n'est pas saisie.
     *
     * @param array<string, string|null> $effects code d'absence => effet
     */
    public static function pending(?string $absence, array $effects): bool
    {
        return $absence !== null && ($effects[$absence] ?? IpesDefaults::ZERO) === IpesDefaults::RETAKE;
    }

    /**
     * @param list<array{kind: string, weight_percent: int, on_twenty: ?float, pending?: bool}> $cells
     */
    public static function compute(array $cells): ?float
    {
        $cc = [];
        $exam = null;
        $retake = null;
        $examWeight = IpesDefaults::WEIGHTS['exam'];
        foreach ($cells as $cell) {
            if ($cell['kind'] === 'exam') {
                $examWeight = $cell['weight_percent'];
                $exam = $cell['on_twenty'];
            } elseif ($cell['kind'] === 'retake') {
                $retake = $cell['on_twenty'];
            } elseif ($cell['pending'] ?? false) {
                return null;
            } elseif ($cell['on_twenty'] !== null) {
                $cc[] = $cell['on_twenty'];
            }
        }
        $exam = $retake ?? $exam;
        if ($exam === null) {
            return null;
        }
        if ($cc === []) {
            return round($exam, 2);
        }
        $ccMark = round(array_sum($cc) / count($cc), 2);

        return round(((100 - $examWeight) * $ccMark + $examWeight * $exam) / 100, 2);
    }
}
