<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Assessment\Application\RuleCalculation;
use Scholaris\Modules\Socle\Assessment\Application\RuleStrategies;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;
use Tests\Support\Assessment\LmdTestStrategy;

/**
 * Tests d'acceptation du moteur (CDC, « Règles de calcul ») : Marie N., L1 S1 Comptabilité et finance, 30 crédits,
 * poids 40/60, arrondi au centième à chaque étape, via une stratégie enregistrée (S6) de type LMD.
 */
final class LmdAcceptanceTest extends TestCase
{
    private RuleCalculation $calculation;

    protected function setUp(): void
    {
        $this->calculation = new RuleCalculation(new RuleEngine(), new RuleStrategies([new LmdTestStrategy()]));
    }

    /** @param array<string, mixed> $options */
    private function rules(array $options = []): RuleSet
    {
        return RuleSet::defaults()->with([
            'strategy' => 'lmd_test',
            'strategy_options' => $options + ['cc_weight' => 0.4, 'exam_weight' => 0.6, 'eliminatory' => 7],
            'decision_labels' => [
                'admitted_with_debts' => ['label' => 'Admis avec dettes', 'passing' => true],
                'repeat' => 'Redouble',
                'excluded' => 'Exclu pour insuffisance',
            ],
        ], 1);
    }

    /**
     * @param float|null $resit note de rattrapage de Mathématiques financières (session « resit »)
     * @return list<array<string, mixed>>
     */
    private function marie(?float $resit = null): array
    {
        $ec = static function (string $code, int $credits, float $cc, float $exam, ?float $resit = null): array {
            $entries = [
                ['value' => $cc, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'cc', 'session' => 'normal'],
                ['value' => $exam, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'exam', 'session' => 'normal'],
            ];
            if ($resit !== null) {
                $entries[] = ['value' => $resit, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => 'resit', 'session' => 'resit'];
            }

            return ['element_id' => $code, 'code' => $code, 'coefficient' => 1.0, 'credits' => $credits, 'entries' => $entries];
        };
        $ue = static fn (string $id, array $groups): array => ['subject_id' => $id, 'code' => $id, 'name' => $id, 'kind' => 'fundamental', 'coefficient' => 1.0, 'credits' => 6, 'groups' => $groups];

        return [
            $ue('UE1', [$ec('Comptabilité générale', 4, 12, 9), $ec('TD de comptabilité', 2, 14, 11)]),
            $ue('UE2', [$ec('Mathématiques financières', 6, 8, 6.5, $resit)]),
            $ue('UE3', [$ec('Microéconomie', 3, 13, 12), $ec('Macroéconomie', 3, 11, 10.5)]),
            $ue('UE4', [$ec('Droit civil', 3, 10, 13), $ec('Institutions administratives', 3, 15, 14)]),
            $ue('UE5', [$ec('Anglais', 3, 14, 15), $ec('Bureautique', 3, 16, 15.5)]),
        ];
    }

    public function testMoyennesDUeDeSemestreLettresEtMgp(): void
    {
        $result = $this->calculation->compute($this->rules(), $this->marie());

        // F2 et arrondi à chaque étape : UE1 = (10,20×4 + 12,20×2) ÷ 6 = 10,8667 → 10,87.
        self::assertSame(['UE1' => 10.87, 'UE2' => 7.1, 'UE3' => 11.55, 'UE4' => 13.1, 'UE5' => 15.15], $result['subjects']);
        // F3 : 346,60 ÷ 30.
        self::assertSame(11.55, $result['average']);
        self::assertSame(['C', 'D', 'C+', 'B', 'A'], array_column($result['units'] ?? [], 'letter'));
        self::assertSame([2.0, 1.0, 2.3, 3.0, 3.7], array_column($result['units'] ?? [], 'points'));
        // F5 : (2,00 + 1,00 + 2,30 + 3,00 + 3,70) × 6 ÷ 30.
        self::assertSame(2.4, $result['extras']['gpa'] ?? null);
    }

    public function testScenarioAEliminatoireSeptUe2AcquiseParCompensation(): void
    {
        $result = $this->calculation->compute($this->rules(['eliminatory' => 7]), $this->marie());

        self::assertSame('compensated', $result['units']['UE2']['status'] ?? null);
        self::assertSame(30, $result['credits_earned']);
        self::assertSame(30, $result['credits_total']);
        self::assertSame('Passable', $result['mention']);
        self::assertSame('Admis par compensation', $result['deliberation_proposal']);
    }

    public function testEliminatoireSeptEtDemiUe2NonAcquise(): void
    {
        $result = $this->calculation->compute($this->rules(['eliminatory' => 7.5]), $this->marie());

        self::assertSame('not_acquired', $result['units']['UE2']['status'] ?? null);
        self::assertSame(24, $result['credits_earned']);
    }

    public function testScenarioBEliminatoireHuitPuisRattrapage(): void
    {
        $normal = $this->calculation->compute($this->rules(['eliminatory' => 8]), $this->marie(11.0));
        self::assertSame(24, $normal['credits_earned'], 'Session normale : la note de rattrapage est ignorée.');
        self::assertSame('Rattrapage', $normal['deliberation_proposal']);
        self::assertNull($normal['mention']);

        $resit = $this->calculation->compute($this->rules(['eliminatory' => 8]), $this->marie(11.0), 'resit');
        // Note d'EC = 0,4 × 8 + 0,6 × 11 (CC conservé, rattrapage remplace l'examen).
        self::assertSame(9.8, $resit['subjects']['UE2']);
        // (346,60 − 42,60 + 58,80) ÷ 30.
        self::assertSame(12.09, $resit['average']);
        self::assertSame('compensated', $resit['units']['UE2']['status'] ?? null);
        self::assertSame(30, $resit['credits_earned']);
        self::assertSame('Assez bien', $resit['mention']);
    }

    public function testSansNoteDeRattrapageLaSessionDeRattrapageGardeLExamen(): void
    {
        $resit = $this->calculation->compute($this->rules(['eliminatory' => 8]), $this->marie(), 'resit');

        self::assertSame(11.55, $resit['average']);
        self::assertSame(24, $resit['credits_earned']);
        self::assertSame('Ajourné', $resit['deliberation_proposal']);
    }

    /** @return array<string, mixed> */
    private function year(int $s1, int $s2, array $context = []): array
    {
        return $this->calculation->decideYear($this->rules(), [
            ['period_id' => 'S1', 'average' => 11.0, 'credits_earned' => $s1, 'credits_total' => 30, 'units' => []],
            ['period_id' => 'S2', 'average' => 12.0, 'credits_earned' => $s2, 'credits_total' => 30, 'units' => []],
        ], $context);
    }

    public function testDecisionsDeFinDAnnee(): void
    {
        self::assertSame('Admis', $this->year(30, 30)['decision']);
        self::assertSame('Admis avec dettes', $this->year(30, 29)['decision']);
        self::assertSame('Admis avec dettes', $this->year(24, 21)['decision']);
        self::assertSame('Redouble', $this->year(24, 20)['decision']);
        // F4 : (11 × 30 + 12 × 30) ÷ 60.
        self::assertSame(11.5, $this->year(30, 30)['average']);
        self::assertSame(60, $this->year(30, 30)['credits_earned']);
    }

    public function testTroisiemeInscriptionExclueEtCentQuatreVingtsCredits(): void
    {
        self::assertSame('Exclu pour insuffisance', $this->year(20, 20, ['enrolments_at_level' => 2])['decision']);
        $final = $this->year(30, 30, ['final_year' => true, 'cumulative_credits' => 120]);
        self::assertSame('Admis', $final['decision']);
        self::assertSame(['cumulative_credits' => 180, 'diploma_file' => true], $final['extras'] ?? []);
    }

    public function testLesDecisionsAdmisAvecDettesValentAdmission(): void
    {
        self::assertSame(['Admis', 'Admis par compensation', 'Admis avec dettes'], $this->rules()->passingLabels());
    }

    public function testUneStrategieAbsenteEstUneErreurJamaisUnRepli(): void
    {
        $calculation = new RuleCalculation(new RuleEngine(), new RuleStrategies());

        $this->expectException(HttpException::class);
        $calculation->compute($this->rules(), $this->marie());
    }
}
