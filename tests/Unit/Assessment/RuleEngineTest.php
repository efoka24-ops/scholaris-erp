<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Assessment\Domain\Lifecycle;
use Scholaris\Modules\Socle\Assessment\Domain\RuleEngine;
use Scholaris\Modules\Socle\Assessment\Domain\RuleSet;

/** Moteur de règles déclaratives : moyenne pondérée, rang, crédits, compensation (T114). */
final class RuleEngineTest extends TestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RuleEngine();
    }

    /**
     * @param list<array{0: float|null, 1: float, 2: float, 3?: string|null}> $entries valeur, barème, poids, absence
     * @return array<string, mixed>
     */
    private function subject(string $id, array $entries, float $coefficient = 1.0, int $credits = 0, string $kind = 'subject'): array
    {
        return [
            'subject_id' => $id, 'kind' => $kind, 'coefficient' => $coefficient, 'credits' => $credits,
            'groups' => [['coefficient' => 1.0, 'entries' => array_map(static fn (array $e): array => ['value' => $e[0], 'max' => $e[1], 'weight' => $e[2], 'absence' => $e[3] ?? null], $entries)]],
        ];
    }

    public function testMoyennePondereeParLePoidsDesEpreuvesRamenéeAuBareme(): void
    {
        // Interrogation 14/20 poids 1, devoir 15/20 poids 2 : (14 + 30) / 3 = 14,67.
        $result = $this->engine->compute(RuleSet::defaults(), [$this->subject('m', [[14, 20, 1], [15, 20, 2]])]);

        self::assertSame(14.67, $result['average']);
        self::assertSame('Bien', $result['mention']);
    }

    public function testUneNoteSurDixEstRamenéeSurVingt(): void
    {
        $result = $this->engine->compute(RuleSet::defaults(), [$this->subject('m', [[7, 10, 1], [10, 20, 1]])]);

        self::assertSame(12.0, $result['average']);
    }

    public function testLesCoefficientsPondèrentLesMatières(): void
    {
        $result = $this->engine->compute(RuleSet::defaults(), [
            $this->subject('maths', [[10, 20, 1]], 4.0),
            $this->subject('sport', [[16, 20, 1]], 1.0),
        ]);

        self::assertSame(11.2, $result['average']);
        self::assertSame(['maths' => 10.0, 'sport' => 16.0], $result['subjects']);
    }

    public function testAbsentJustifieEstExcluDuCalcul(): void
    {
        $result = $this->engine->compute(RuleSet::defaults(), [$this->subject('m', [[14, 20, 1], [null, 20, 2, 'justified']])]);

        self::assertSame(14.0, $result['average']);
    }

    public function testAbsentNonJustifieVautZeroParDefautOuEstExcluSelonLaRegle(): void
    {
        $subjects = [$this->subject('m', [[14, 20, 1], [null, 20, 1, 'unjustified']])];

        self::assertSame(7.0, $this->engine->compute(RuleSet::defaults(), $subjects)['average']);
        self::assertSame(14.0, $this->engine->compute(RuleSet::defaults()->with(['unjustified_absence' => 'excluded'], 1), $subjects)['average']);
    }

    public function testUneNoteManquanteNeCompteNiCommeZeroNiCommeAbsence(): void
    {
        $result = $this->engine->compute(RuleSet::defaults(), [$this->subject('m', [[12, 20, 1], [null, 20, 1]])]);

        self::assertSame(12.0, $result['average']);
    }

    public function testSansAucuneNoteLaMoyenneEstNulle(): void
    {
        $result = $this->engine->compute(RuleSet::defaults(), [$this->subject('m', [[null, 20, 1]])]);

        self::assertNull($result['average']);
        self::assertNull($result['mention']);
    }

    public function testLeResultatEstDeterministeEtSensibleALArrondiDeclare(): void
    {
        $subjects = [$this->subject('m', [[14, 20, 1], [15, 20, 2]])];
        $one = RuleSet::defaults()->with(['rounding' => 1], 1);

        self::assertSame($this->engine->compute(RuleSet::defaults(), $subjects), $this->engine->compute(RuleSet::defaults(), $subjects));
        self::assertSame(14.7, $this->engine->compute($one, $subjects)['average']);
    }

    public function testRangsAvecExAequoEtSansMoyenne(): void
    {
        $ranks = $this->engine->ranks(['a' => 15.0, 'b' => 12.0, 'c' => 15.0, 'd' => null, 'e' => 9.0]);

        self::assertSame(['a' => 1, 'b' => 3, 'c' => 1, 'd' => null, 'e' => 4], $ranks);
    }

    public function testCreditsAcquisDirectementParCompensationOuPas(): void
    {
        $rules = RuleSet::defaults();
        $units = fn (float $a, float $b): array => [
            $this->subject('ue1', [[$a, 20, 1]], 1.0, 6, 'teaching_unit'),
            $this->subject('ue2', [[$b, 20, 1]], 1.0, 4, 'teaching_unit'),
        ];

        $direct = $this->engine->compute($rules, $units(12, 11));
        self::assertSame([10, 10], [$direct['credits_earned'], $direct['credits_total']]);
        self::assertSame('Admis', $direct['deliberation_proposal']);

        // UE2 à 8/20 (compensation à partir de 8), moyenne générale 10,4 >= 10 : crédits acquis par compensation.
        $compensated = $this->engine->compute($rules, $units(12, 8));
        self::assertSame(10, $compensated['credits_earned']);
        self::assertSame('Admis par compensation', $compensated['deliberation_proposal']);

        // UE2 à 7 : sous le seuil de compensation, seuls les crédits de l'UE1 sont acquis.
        $resit = $this->engine->compute($rules, $units(14, 7));
        self::assertSame(6, $resit['credits_earned']);
        self::assertSame('Rattrapage', $resit['deliberation_proposal']);

        self::assertSame('Ajourné', $this->engine->compute($rules, $units(5, 6))['deliberation_proposal']);
    }

    public function testPonderationParLesCreditsQuandLaRegleLeDemande(): void
    {
        $rules = RuleSet::defaults()->with(['weighting' => 'credits'], 2);
        $result = $this->engine->compute($rules, [
            $this->subject('ue1', [[10, 20, 1]], 1.0, 6, 'teaching_unit'),
            $this->subject('ue2', [[16, 20, 1]], 1.0, 2, 'teaching_unit'),
        ]);

        self::assertSame(11.5, $result['average']);
    }

    public function testPropositionDuConseilSelonLaMoyenneEtLesAbsences(): void
    {
        $rules = RuleSet::defaults();

        self::assertSame('Félicitations', $this->engine->councilProposal($rules, 16.5, 0));
        self::assertSame('Tableau d\'honneur', $this->engine->councilProposal($rules, 14.0, 2));
        self::assertSame('Encouragements', $this->engine->councilProposal($rules, 12.0, 0));
        self::assertNull($this->engine->councilProposal($rules, 10.0, 0));
        self::assertSame('Avertissement travail', $this->engine->councilProposal($rules, 7.5, 0));
        self::assertSame('Blâme', $this->engine->councilProposal($rules, 15.0, 12));
    }

    public function testUneRegleIncoherenteEstRefuseeParLApiEtIgnoreeParLesParametres(): void
    {
        try {
            RuleSet::defaults()->with(['pass_mark' => 25], 1);
            self::fail('Seuil au-dessus du barème : refus attendu.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('pass_mark', $e->getMessage());
        }
        try {
            RuleSet::defaults()->with(['invente' => true], 1);
            self::fail('Règle inconnue : refus attendu.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('invente', $e->getMessage());
        }

        $lenient = RuleSet::defaults()->withLenient(['pass_mark' => 25, 'rounding' => 1, 'scale' => 0], 3);
        self::assertSame(10.0, $lenient->passMark);
        self::assertSame(1, $lenient->rounding);
        self::assertSame(20.0, $lenient->scale);
        self::assertSame(3, $lenient->version);
    }

    public function testLesMentionsSaisiesDansLeDesordreSontReordonnees(): void
    {
        $rules = RuleSet::defaults()->with(['mentions' => [['min' => 0, 'label' => 'Faible'], ['min' => 14, 'label' => 'Bien'], ['min' => 10, 'label' => 'Passable']]], 1);

        self::assertSame('Bien', $this->engine->mention($rules, 15.0));
        self::assertSame('Passable', $this->engine->mention($rules, 11.0));
    }

    public function testCycleDeVieParDefautEtDeclarationIncoherente(): void
    {
        $default = Lifecycle::default();
        self::assertSame('draft', $default->initial());
        self::assertTrue($default->isEditable('entry'));
        self::assertFalse($default->isEditable('locked'));
        self::assertNull($default->transition('entry', 'published'));

        self::assertNull(Lifecycle::fromArray(['states' => ['a', 'b'], 'editable' => ['a'], 'published' => 'zz', 'transitions' => []]));
        self::assertNull(Lifecycle::fromArray(['states' => ['draft', 'published'], 'editable' => ['draft'], 'published' => 'published', 'transitions' => [['from' => 'draft', 'to' => 'inconnu', 'permission' => 'assessment.enter']]]));
    }
}
