<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Domain;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Note d'un EC (F1) ou d'une matière BTS/HND : poids CC × moyenne du contrôle continu + poids examen × examen. En session de
 * rattrapage, la note de rattrapage remplace l'examen et le CC est conservé. Arrondi au centième.
 *
 * @phpstan-import-type Entry from RuleStrategy
 */
final class Marks
{
    public const CC_KINDS = ['cc', 'homework', 'continuous', 'devoir', 'tp'];
    public const EXAM_KINDS = ['exam'];
    public const RESIT_KINDS = ['resit'];

    /**
     * @param list<Entry> $entries
     * @return array{cc: float|null, exam: float|null, resit: float|null, note: float|null, resat: bool}
     */
    public static function note(Rules $rules, array $entries, string $session, float $ccWeight): array
    {
        $cc = self::average($rules, $entries, $rules->kinds('cc_kinds', self::CC_KINDS), 'normal');
        $exam = self::average($rules, $entries, $rules->kinds('exam_kinds', self::EXAM_KINDS), 'normal');
        $resit = $session === 'resit' ? self::average($rules, $entries, $rules->kinds('resit_kinds', self::RESIT_KINDS), 'resit') : null;
        $used = $resit ?? $exam;
        $note = match (true) {
            $cc === null && $used === null => null,
            $cc === null => $rules->round((float) $used),
            $used === null => $rules->round($cc),
            default => $rules->round($ccWeight * $cc + (1.0 - $ccWeight) * $used),
        };

        return [
            'cc' => $cc === null ? null : $rules->round($cc),
            'exam' => $exam === null ? null : $rules->round($exam),
            'resit' => $resit === null ? null : $rules->round($resit),
            'note' => $note,
            'resat' => $resit !== null,
        ];
    }

    /**
     * @param list<Entry> $entries
     * @param list<string> $kinds
     */
    private static function average(Rules $rules, array $entries, array $kinds, string $session): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($entries as $entry) {
            if (!in_array($entry['kind'], $kinds, true) || $entry['weight'] <= 0.0 || ($session === 'resit') !== ($entry['session'] === 'resit')) {
                continue;
            }
            if ($entry['absence'] !== null) {
                if ($entry['effect'] !== CalculationRules::EFFECT_ZERO) {
                    continue;
                }
                $value = 0.0;
            } elseif ($entry['value'] === null || $entry['max'] <= 0.0) {
                continue;
            } else {
                $value = $entry['value'] / $entry['max'] * $rules->scale();
            }
            $sum += $value * $entry['weight'];
            $weights += $entry['weight'];
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }

    /**
     * Rang avec ex æquo au même rang (1, 2, 2, 4) ; null sans moyenne.
     *
     * @param array<string, float|null> $averages
     * @return array<string, int|null>
     */
    public static function ranks(array $averages): array
    {
        $ranks = [];
        foreach ($averages as $id => $average) {
            if ($average === null) {
                $ranks[$id] = null;
                continue;
            }
            $better = 0;
            foreach ($averages as $other) {
                if ($other !== null && $other > $average) {
                    ++$better;
                }
            }
            $ranks[$id] = $better + 1;
        }

        return $ranks;
    }
}
