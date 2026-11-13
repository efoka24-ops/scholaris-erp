<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Admissions\Domain;

/**
 * Classement d'une campagne (EX-ADM-04/05) : note décroissante (centièmes de point sur 20), ex æquo au même rang (1, 2, 2, 4) ;
 * liste principale = les candidats classés dans la limite des places, en gardant ensemble les ex æquo du dernier rang ; les autres
 * forment la liste d'attente. Aucun candidat sans note n'est classé. La décision reste prise par le directeur d'école (R04).
 */
final class Ranking
{
    public const MAIN = 'main';
    public const WAITING = 'waiting';

    private function __construct()
    {
    }

    /**
     * @param array<string, int> $scores identifiant => note en centièmes
     * @return array<string, array{rank: int, list: string}>
     */
    public static function rank(array $scores, int $seats): array
    {
        arsort($scores);
        $result = [];
        $position = 0;
        $rank = 0;
        $previous = null;
        foreach ($scores as $id => $score) {
            ++$position;
            if ($score !== $previous) {
                $rank = $position;
                $previous = $score;
            }
            $result[(string) $id] = ['rank' => $rank, 'list' => $rank <= $seats ? self::MAIN : self::WAITING];
        }

        return $result;
    }
}
