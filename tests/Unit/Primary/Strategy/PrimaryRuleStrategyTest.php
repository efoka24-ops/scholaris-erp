<?php

declare(strict_types=1);

namespace Tests\Unit\Primary\Strategy;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Primary\Grades\Domain\Strategy\PrimaryRuleStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\CalculationRules;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;

/**
 * Stratégie `primary` avec des règles d'EXEMPLE (`example_*`) FOURNIES PAR LE TEST : aucun barème ni seuil n'est celui d'un texte
 * officiel (questions Q3, Q4, Q10 sans réponse). Ces chiffres ne valident que le mécanisme paramétré ; les tests chiffrés officiels
 * seront écrits après les réponses du client.
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class PrimaryRuleStrategyTest extends TestCase
{
    /** @param array<string, mixed> $options */
    private function exampleRules(float $scale, float $passMark, int $rounding = 2, array $options = []): CalculationRules
    {
        return new CalculationRules(
            1,
            'primary',
            $scale,
            $passMark,
            $rounding,
            [['min' => 16.0, 'label' => 'example_high'], ['min' => 10.0, 'label' => 'example_mid']],
            0.0,
            0.0,
            0.0,
            $options,
            ['admitted' => 'example_admis', 'adjourned' => 'example_redouble', 'cycle_end' => 'example_fin_cycle'],
            ['admitted'],
            ['abs' => CalculationRules::EFFECT_ZERO, 'just' => CalculationRules::EFFECT_EXCLUDED],
        );
    }

    /** @return array{value: float|null, max: float, weight: float, absence: string|null, effect: string|null, kind: string, session: string} */
    private function entry(?float $value, float $max, float $weight = 1.0, ?string $absence = null): array
    {
        return ['value' => $value, 'max' => $max, 'weight' => $weight, 'absence' => $absence, 'effect' => null, 'kind' => 'cc', 'session' => 'normal'];
    }

    /**
     * @param list<array{value: float|null, max: float, weight: float, absence: string|null, effect: string|null, kind: string, session: string}> $entries
     * @return Unit
     */
    private function unit(string $id, float $coefficient, array $entries): array
    {
        return ['subject_id' => $id, 'code' => $id, 'name' => $id, 'kind' => 'subject', 'coefficient' => $coefficient, 'credits' => 0, 'groups' => [['element_id' => null, 'code' => null, 'coefficient' => 1.0, 'credits' => 0, 'entries' => $entries]]];
    }

    public function testMoyenneDeMatiereEtGeneraleSuiventLeBaremeEtLesCoefficientsDesRegles(): void
    {
        $strategy = new PrimaryRuleStrategy();
        // Exemple : barème 20 ; coefficients 2 et 1, notes 10 et 16 -> (10*2 + 16*1) / 3 = 12.
        $rules = $this->exampleRules(20.0, 10.0);
        $result = $strategy->computeLearner($rules, [
            $this->unit('m1', 2.0, [$this->entry(10.0, 20.0)]),
            $this->unit('m2', 1.0, [$this->entry(16.0, 20.0)]),
        ], 'normal');
        self::assertSame(12.0, $result['average']);
        self::assertSame(['m1' => 10.0, 'm2' => 16.0], $result['subjects']);
        self::assertSame('example_mid', $result['mention']);
        self::assertSame('example_admis', $result['deliberation_proposal']);
    }

    public function testLeBaremeDesReglesChangeTout(): void
    {
        $strategy = new PrimaryRuleStrategy();
        $unit = $this->unit('m1', 1.0, [$this->entry(7.0, 10.0), $this->entry(9.0, 10.0, 3.0)]);
        // Barème 10 : (7 + 9*3) / 4 = 8,5.
        self::assertSame(8.5, $strategy->computeLearner($this->exampleRules(10.0, 5.0), [$unit], 'normal')['average']);
        // Même notes ramenées à un barème 20 : 17.
        self::assertSame(17.0, $strategy->computeLearner($this->exampleRules(20.0, 5.0), [$unit], 'normal')['average']);
        // Arrondi aux paramètres de l'école.
        $third = $this->unit('m1', 1.0, [$this->entry(1.0, 3.0)]);
        self::assertSame(6.67, $strategy->computeLearner($this->exampleRules(20.0, 10.0, 2), [$third], 'normal')['average']);
        self::assertSame(7.0, $strategy->computeLearner($this->exampleRules(20.0, 10.0, 0), [$third], 'normal')['average']);
    }

    public function testAbsenceSuitLEffetDeclareEtLaSessionDeRattrapageEstIgnoree(): void
    {
        $strategy = new PrimaryRuleStrategy();
        $rules = $this->exampleRules(20.0, 10.0);
        $zero = $this->unit('m1', 1.0, [$this->entry(20.0, 20.0), $this->entry(null, 20.0, 1.0, 'abs')]);
        self::assertSame(10.0, $strategy->computeLearner($rules, [$zero], 'normal')['average']);
        $excluded = $this->unit('m1', 1.0, [$this->entry(20.0, 20.0), $this->entry(null, 20.0, 1.0, 'just')]);
        self::assertSame(20.0, $strategy->computeLearner($rules, [$excluded], 'normal')['average']);
        $resit = $this->entry(0.0, 20.0);
        $resit['session'] = 'resit';
        self::assertSame(20.0, $strategy->computeLearner($rules, [$this->unit('m1', 1.0, [$this->entry(20.0, 20.0), $resit])], 'normal')['average']);
        self::assertNull($strategy->computeLearner($rules, [$this->unit('m1', 1.0, [$this->entry(null, 20.0)])], 'normal')['average']);
        self::assertSame('example_redouble', $strategy->computeLearner($rules, [], 'normal')['deliberation_proposal']);
    }

    public function testAggregationDesPeriodesPonidereeParLesOptions(): void
    {
        $strategy = new PrimaryRuleStrategy();
        $periods = [
            ['period_id' => 's1', 'average' => 12.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => ['m1' => ['average' => 12.0]]],
            ['period_id' => 's2', 'average' => 14.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => ['m1' => ['average' => 14.0]]],
        ];
        // Exemple : 2 séquences de 12 et 14, poids par défaut -> trimestre 13,00.
        $equal = $strategy->aggregatePeriods($this->exampleRules(20.0, 10.0), $periods);
        self::assertSame(13.0, $equal['average']);
        self::assertSame(13.0, $equal['units']['m1']['average']);
        // Poids de période fournis par les règles : (12*1 + 14*3) / 4 = 13,5.
        $weighted = $strategy->aggregatePeriods($this->exampleRules(20.0, 10.0, 2, ['period_weights' => ['s1' => 1, 's2' => 3]]), $periods);
        self::assertSame(13.5, $weighted['average']);
        $zero = $strategy->aggregatePeriods($this->exampleRules(20.0, 10.0, 2, ['period_weights' => ['s1' => 0]]), $periods);
        self::assertSame(14.0, $zero['average']);
    }

    public function testDecisionDeFinDAnneeSuitLaNoteDePassageEtLeNiveauFinal(): void
    {
        $strategy = new PrimaryRuleStrategy();
        $periods = [
            ['period_id' => 't1', 'average' => 9.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
            ['period_id' => 't2', 'average' => 12.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
        ];
        $admitted = $strategy->decideYear($this->exampleRules(20.0, 10.0), $periods, []);
        self::assertSame([10.5, 'example_admis'], [$admitted['average'], $admitted['decision']]);
        $repeat = $strategy->decideYear($this->exampleRules(20.0, 11.0), $periods, []);
        self::assertSame('example_redouble', $repeat['decision']);
        $final = $strategy->decideYear($this->exampleRules(20.0, 10.0), $periods, ['is_final_level' => true]);
        self::assertSame('example_fin_cycle', $final['decision']);
        self::assertSame('example_redouble', $strategy->decideYear($this->exampleRules(20.0, 10.0), [], [])['decision']);
    }

    public function testOptionsInconnuesOuInvalidesSontRefusees(): void
    {
        $strategy = new PrimaryRuleStrategy();
        self::assertSame([], $strategy->optionErrors([]));
        self::assertSame([], $strategy->optionErrors(['period_weights' => ['t1' => 1, 't2' => 2.5]]));
        self::assertNotSame([], $strategy->optionErrors(['inconnue' => 1]));
        self::assertNotSame([], $strategy->optionErrors(['period_weights' => 'x']));
        self::assertNotSame([], $strategy->optionErrors(['period_weights' => ['t1' => -1]]));
        self::assertSame('primary', $strategy->key());
    }

    /**
     * T534 chiffré avec les VALEURS DE DÉPART de questions.md (Q3, Q4, Q10 : barème 20, note de passage 10, arrondi 2, coefficients
     * et poids 1), à confirmer : chaîne complète séquences -> trimestre -> année -> proposition, épreuves sur 10 ramenées sur 20,
     * absence non justifiée = zéro, absence justifiée exclue.
     */
    public function testChaineCompleteAvecLesValeursDeDepartAConfirmer(): void
    {
        $strategy = new PrimaryRuleStrategy();
        $rules = $this->exampleRules(20.0, 10.0);
        // Séquence 1 : lecture 7/10 et 8/10 -> 15/20 ; calcul 6/10 -> 12/20 ; générale (15 + 12) / 2 = 13,5.
        $s1 = $strategy->computeLearner($rules, [$this->unit('LECT', 1.0, [$this->entry(7.0, 10.0), $this->entry(8.0, 10.0)]), $this->unit('CALC', 1.0, [$this->entry(6.0, 10.0)])], 'normal');
        self::assertSame(13.5, $s1['average']);
        // Séquence 2 : lecture 9/10 -> 18 ; calcul absent non justifié (zéro) et 5/10 -> (0 + 10) / 2 = 5 ; générale 11,5.
        $s2 = $strategy->computeLearner($rules, [$this->unit('LECT', 1.0, [$this->entry(9.0, 10.0)]), $this->unit('CALC', 1.0, [$this->entry(null, 10.0, 1.0, 'abs'), $this->entry(5.0, 10.0)])], 'normal');
        self::assertSame(11.5, $s2['average']);
        // Absence justifiée exclue : calcul = 5/10 seul -> 10 ; générale (18 + 10) / 2 = 14.
        $s2bis = $strategy->computeLearner($rules, [$this->unit('LECT', 1.0, [$this->entry(9.0, 10.0)]), $this->unit('CALC', 1.0, [$this->entry(null, 10.0, 1.0, 'just'), $this->entry(5.0, 10.0)])], 'normal');
        self::assertSame(14.0, $s2bis['average']);

        $summary = static fn (string $id, array $r): array => ['period_id' => $id, 'average' => $r['average'], 'credits_earned' => 0, 'credits_total' => 0, 'units' => array_map(static fn (?float $a): array => ['average' => $a], $r['subjects'])];
        $t1 = $strategy->aggregatePeriods($rules, [$summary('s1', $s1), $summary('s2', $s2)]);
        self::assertSame(12.5, $t1['average'], 'Trimestre = moyenne des séquences, poids 1.');
        self::assertSame(16.5, $t1['units']['LECT']['average']);
        $year = $strategy->decideYear($rules, [
            ['period_id' => 't1', 'average' => 12.5, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
            ['period_id' => 't2', 'average' => 8.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
            ['period_id' => 't3', 'average' => 9.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
        ], []);
        self::assertSame(9.83, $year['average'], 'Année = moyenne des trimestres arrondie au centième.');
        self::assertSame('adjourned', $year['extras']['decision_code'] ?? null, '9,83 < 10 : redouble (proposition, le conseil décide).');
        $weighted = $strategy->decideYear($this->exampleRules(20.0, 10.0, 2, ['period_weights' => ['t1' => 2]]), [
            ['period_id' => 't1', 'average' => 12.5, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
            ['period_id' => 't2', 'average' => 8.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
            ['period_id' => 't3', 'average' => 9.0, 'credits_earned' => 0, 'credits_total' => 0, 'units' => []],
        ], []);
        self::assertSame(10.5, $weighted['average'], 'Poids de période paramétré (exemple) : (12,5 × 2 + 8 + 9) / 4.');
        self::assertSame('admitted', $weighted['extras']['decision_code'] ?? null);
    }
}
