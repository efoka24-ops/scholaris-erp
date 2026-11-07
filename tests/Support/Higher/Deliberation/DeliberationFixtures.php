<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Deliberation;

use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;

/** Jeux d'essai du CDC pour la délibération : règles LMD et BTS/HND par défaut, semestre de Marie N. (L1 S1, 30 crédits). */
final class DeliberationFixtures
{
    public const LABELS = [
        'admitted' => 'Admis', 'compensated' => 'Admis par compensation', 'resit' => 'Rattrapage', 'adjourned' => 'Ajourné',
        'admitted_with_debts' => 'Admis avec dettes', 'repeat' => 'Redouble', 'excluded' => 'Exclu pour insuffisance',
    ];

    public const MENTIONS = [
        ['min' => 16.0, 'label' => 'Très bien'], ['min' => 14.0, 'label' => 'Bien'],
        ['min' => 12.0, 'label' => 'Assez bien'], ['min' => 10.0, 'label' => 'Passable'],
    ];

    /** @param array<string, mixed> $options */
    public static function rules(array $options = [], string $strategy = 'higher_lmd'): CalculationRules
    {
        return new CalculationRules(
            1,
            $strategy,
            20.0,
            10.0,
            2,
            self::MENTIONS,
            10.0,
            10.0,
            10.0,
            $options + ['cc_weight_percent' => 40, 'eliminatory_unit_mark' => 7],
            self::LABELS,
            ['admitted', 'compensated', 'admitted_with_debts'],
            ['justified' => 'excluded', 'unjustified' => 'zero'],
        );
    }

    /** @return array<string, mixed> */
    public static function ec(string $code, int $credits, ?float $cc, ?float $exam, ?float $resit = null): array
    {
        $entries = [];
        if ($cc !== null) {
            $entries[] = ['value' => $cc, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'cc', 'session' => 'normal'];
        }
        if ($exam !== null) {
            $entries[] = ['value' => $exam, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'exam', 'session' => 'normal'];
        }
        if ($resit !== null) {
            $entries[] = ['value' => $resit, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'resit', 'session' => 'resit'];
        }

        return ['element_id' => 'el-' . $code, 'code' => $code, 'coefficient' => 1.0, 'credits' => $credits, 'entries' => $entries];
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return array<string, mixed>
     */
    public static function ue(string $code, array $groups, int $credits = 6, float $coefficient = 1.0): array
    {
        return ['subject_id' => 'sub-' . $code, 'code' => $code, 'name' => 'Unité ' . $code, 'kind' => 'fundamental', 'coefficient' => $coefficient, 'credits' => $credits, 'groups' => $groups];
    }

    /**
     * Marie N. (CDC) ; $resit : note de rattrapage de Mathématiques financières.
     *
     * @return list<array<string, mixed>>
     */
    public static function marie(?float $resit = null): array
    {
        return [
            self::ue('UE1', [self::ec('CG', 4, 12, 9), self::ec('TDC', 2, 14, 11)]),
            self::ue('UE2', [self::ec('MF', 6, 8, 6.5, $resit)]),
            self::ue('UE3', [self::ec('MIC', 3, 13, 12), self::ec('MAC', 3, 11, 10.5)]),
            self::ue('UE4', [self::ec('DC', 3, 10, 13), self::ec('IA', 3, 15, 14)]),
            self::ue('UE5', [self::ec('ANG', 3, 14, 15), self::ec('BUR', 3, 16, 15.5)]),
        ];
    }
}
