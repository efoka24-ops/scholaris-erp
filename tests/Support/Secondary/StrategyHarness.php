<?php

declare(strict_types=1);

namespace Tests\Support\Secondary;

use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;

/**
 * Banc d'essai de la stratégie `secondary` sans base : construit les entrées que le socle lui donne (unités, groupes, notes),
 * simule l'enregistrement d'un résultat (détail fusionné puis aller-retour JSON, comme ResultService et la colonne `details`)
 * et enchaîne séquences → trimestre → année. Les valeurs de règles sont celles du jeu d'exemple `example_cdc`, que chaque test
 * peut remplacer.
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class StrategyHarness
{
    public readonly SecondaryStrategy $strategy;
    public readonly CalculationRules $rules;

    /** @param array<string, mixed> $definition remplace des clés de Defaults::ruleDefinition() */
    public function __construct(array $definition = [], ?SecondaryStrategy $strategy = null)
    {
        $this->strategy = $strategy ?? new SecondaryStrategy();
        $this->rules = RuleSet::defaults()->with($definition + Defaults::ruleDefinition(), 1)->toCalculationRules();
    }

    /**
     * Une matière, un groupe, une épreuve par note (sur 20, poids 1 sauf indication). Note null = aucune épreuve.
     *
     * @param list<float|int|array{0: float|int|null, 1: float|int}|null> $notes note ou [note, poids] ; [null, poids] = absence
     * @return Unit
     */
    public static function unit(string $id, float $coefficient, array $notes, string $kind = 'subject'): array
    {
        $entries = [];
        foreach ($notes as $note) {
            if ($note === null) {
                continue;
            }
            [$value, $weight] = is_array($note) ? $note : [$note, 1];
            $entries[] = [
                'value' => $value === null ? null : (float) $value, 'max' => 20.0, 'weight' => (float) $weight, 'absence' => $value === null ? 'excused' : null,
                'effect' => $value === null ? CalculationRules::EFFECT_EXCLUDED : null, 'kind' => 'devoir', 'session' => 'normal',
            ];
        }

        return [
            'subject_id' => $id, 'code' => strtoupper($id), 'name' => 'Matière ' . $id, 'kind' => $kind, 'coefficient' => $coefficient, 'credits' => 0,
            'groups' => [['element_id' => null, 'code' => null, 'coefficient' => 1.0, 'credits' => 0, 'entries' => $entries]],
        ];
    }

    /**
     * Calcul d'une séquence puis résumé tel que le socle le relit pour l'agrégat.
     *
     * @param list<Unit> $units
     * @return array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>, result: array<string, mixed>}
     */
    public function sequence(string $periodId, array $units): array
    {
        $result = $this->strategy->computeLearner($this->rules, $units, 'normal');
        $details = [];
        foreach ($result['subjects'] as $subjectId => $average) {
            $details[] = ['subject_id' => (string) $subjectId, 'code' => strtoupper((string) $subjectId), 'name' => null, 'average' => $average] + ($result['units'][$subjectId] ?? []);
        }

        return self::summary($periodId, $result['average'], $details) + ['result' => $result];
    }

    /**
     * Agrégat (trimestre ou année) puis résumé relu.
     *
     * @param list<array<string, mixed>> $children résumés des enfants
     * @return array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>, result: array<string, mixed>}
     */
    public function aggregate(string $periodId, array $children): array
    {
        $result = $this->strategy->aggregatePeriods($this->rules, self::strip($children));
        $details = [];
        foreach ($result['units'] as $subjectId => $unit) {
            $details[] = ['subject_id' => (string) $subjectId, 'code' => strtoupper((string) $subjectId), 'name' => null, 'average' => $unit['average'] ?? null] + $unit;
        }

        return self::summary($periodId, $result['average'], $details) + ['result' => $result];
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function decideYear(array $children, array $context = []): array
    {
        return $this->strategy->decideYear($this->rules, self::strip($children), $context);
    }

    /**
     * Résumé d'une période connue seulement par sa moyenne générale (2e et 3e trimestres de TST-02 : le CDC ne donne pas leur
     * détail par matière).
     *
     * @return array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}
     */
    public static function known(string $periodId, float $average): array
    {
        return ['period_id' => $periodId, 'average' => $average, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []];
    }

    /**
     * @param list<array<string, mixed>> $details
     * @return array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}
     */
    private static function summary(string $periodId, ?float $average, array $details): array
    {
        $stored = json_decode(json_encode($details, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $units = [];
        foreach ($stored as $detail) {
            $units[(string) $detail['subject_id']] = $detail;
        }

        return ['period_id' => $periodId, 'average' => $average, 'credits_earned' => 0, 'credits_total' => 0, 'units' => $units];
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return list<array{period_id: string, average: float|null, credits_earned: int, credits_total: int, units: array<string, array<string, mixed>>}>
     */
    private static function strip(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            $out[] = [
                'period_id' => (string) $child['period_id'], 'average' => $child['average'] === null ? null : (float) $child['average'],
                'credits_earned' => 0, 'credits_total' => 0, 'units' => (array) $child['units'],
            ];
        }

        return $out;
    }
}
