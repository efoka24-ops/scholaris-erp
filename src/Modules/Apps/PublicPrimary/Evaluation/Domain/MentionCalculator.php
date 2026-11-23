<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Domain;

/**
 * Calcul pur de la mention d'une discipline pour une unité (EX-EVA-04), sur les paramètres fournis (MentionRules) :
 *  - un critère est maîtrisé si ses occasions réussies atteignent `masteryMinSuccess` ;
 *  - mention « tous » si tous les critères minimaux sont maîtrisés, « certains » si au moins un l'est, « aucun » sinon ;
 *  - un élève absent à toutes les occasions de tous les critères minimaux reçoit « non évalué », jamais la mention « aucun » (RG-EVA-4) ;
 *  - le critère de perfectionnement est examiné (et signalé) seulement si la mention est « tous » (paramètre) ;
 *  - les critères minimaux non maîtrisés forment la liste de remédiation (EX-EVA-05).
 */
final class MentionCalculator
{
    public const SUCCESS = 'success';
    public const FAIL = 'fail';
    public const ABSENT = 'absent';
    public const OUTCOMES = [self::SUCCESS, self::FAIL, self::ABSENT];

    private function __construct()
    {
    }

    /**
     * @param list<array{id: string, kind: string}> $criteria `kind` : minimal ou perfection
     * @param array<string, array<int, string>> $outcomes critère => occasion => success|fail|absent
     */
    public static function compute(MentionRules $rules, array $criteria, array $outcomes): MentionResult
    {
        $successes = [];
        $mastered = [];
        $notMastered = [];
        $assessedMinimal = 0;
        $minimal = 0;
        $perfection = false;
        $perfectionIds = [];
        foreach ($criteria as $criterion) {
            $cells = $outcomes[$criterion['id']] ?? [];
            $success = 0;
            $assessed = false;
            foreach ($cells as $occasion => $outcome) {
                if ($occasion < 1 || $occasion > $rules->occasions) {
                    continue;
                }
                if ($outcome !== self::ABSENT) {
                    $assessed = true;
                }
                if ($outcome === self::SUCCESS) {
                    ++$success;
                }
            }
            $successes[$criterion['id']] = $success;
            $isMastered = $assessed && $success >= $rules->masteryMinSuccess;
            if ($criterion['kind'] === 'perfection') {
                $perfectionIds[$criterion['id']] = $isMastered;
                continue;
            }
            ++$minimal;
            if ($assessed) {
                ++$assessedMinimal;
            }
            if ($isMastered) {
                $mastered[] = $criterion['id'];
            } else {
                $notMastered[] = $criterion['id'];
            }
        }

        if ($minimal === 0 || $assessedMinimal === 0) {
            return new MentionResult(MentionRules::NOT_ASSESSED, [], [], false, $successes);
        }
        $count = count($mastered);
        $mention = $count === $minimal ? $rules->allCode : ($count > 0 ? $rules->someCode : $rules->noneCode);
        if (!$rules->perfectionOnlyIfAll || $mention === $rules->allCode) {
            $perfection = in_array(true, $perfectionIds, true);
        }

        return new MentionResult($mention, $mastered, $notMastered, $perfection, $successes);
    }
}
