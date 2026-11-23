<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SecondaryStrategy;
use Scholaris\Modules\Socle\Assessment\Contracts\RuleStrategy;
use Tests\Support\Secondary\StrategyHarness;

/**
 * T900 (lot E3-A) : options de la stratégie `secondary` portées par la version de règles du niveau. Coefficients versionnés de
 * Setup (`coefficients`), matière hors moyenne officielle, rôle `practical`, matière non évaluée (`not_evaluated`) et composition
 * de fin d'année (`final_exam`). Données fictives.
 *
 * @phpstan-import-type Unit from RuleStrategy
 */
final class LevelStrategyOptionsTest extends TestCase
{
    /** @param array<string, mixed> $options */
    private static function harness(array $options): StrategyHarness
    {
        return new StrategyHarness(['strategy_options' => $options + Defaults::STRATEGY_OPTIONS]);
    }

    public function testLesCoefficientsDuNiveauRemplacentCeuxDuReferentiel(): void
    {
        $units = [StrategyHarness::unit('math', 1.0, [16]), StrategyHarness::unit('fr', 1.0, [10])];
        self::assertSame(13.0, self::harness([])->sequence('S1', $units)['average'], 'Référentiel : coefficients 1 et 1.');

        $level = self::harness([SecondaryStrategy::OPTION_COEFFICIENTS => ['math' => ['coefficient' => 3.0, 'counts_in_average' => true, 'role' => 'official'], 'fr' => 1.0]]);
        $sequence = $level->sequence('S1', $units);
        self::assertSame(14.5, $sequence['average'], '(16 × 3 + 10 × 1) ÷ 4.');
        self::assertEquals(3.0, $sequence['units']['math']['coefficient'], 'Le coefficient en vigueur est celui qui est enregistré.');

        $trimester = $level->aggregate('T1', [$sequence, $level->sequence('S2', $units)]);
        self::assertSame(14.5, $trimester['average']);
        self::assertEquals(3.0, $trimester['units']['math']['coefficient']);
    }

    public function testMatiereHorsMoyenneOfficielleEtRolePratique(): void
    {
        $level = self::harness([SecondaryStrategy::OPTION_COEFFICIENTS => [
            'projet' => ['coefficient' => 2.0, 'counts_in_average' => false, 'role' => 'project'],
            'atelier' => ['coefficient' => 2.0, 'role' => SecondaryStrategy::ROLE_PRACTICAL],
        ]]);
        $sequence = $level->sequence('S1', [StrategyHarness::unit('projet', 2.0, [4]), StrategyHarness::unit('atelier', 2.0, [7]), StrategyHarness::unit('fr', 2.0, [13])]);

        self::assertSame(10.0, $sequence['average'], 'Le projet (RG-ORG-4) est hors moyenne : (7 × 2 + 13 × 2) ÷ 4.');
        self::assertEquals(4.0, $sequence['units']['projet']['average'], 'Sa moyenne propre reste calculée.');
        self::assertSame(['atelier'], $sequence['result']['extras']['practical_alerts'], 'Rôle `practical` de Setup : pratique sous le seuil.');
    }

    public function testMatiereNonEvalueeExclueOuCompteeZero(): void
    {
        $units = [StrategyHarness::unit('math', 2.0, [12]), StrategyHarness::unit('svt', 2.0, [])];
        $excluded = self::harness([])->sequence('S1', $units);
        self::assertSame(12.0, $excluded['average']);
        self::assertSame(['svt'], $excluded['result']['extras']['not_evaluated']);

        $zero = self::harness([SecondaryStrategy::OPTION_NOT_EVALUATED => SecondaryStrategy::NOT_EVALUATED_ZERO])->sequence('S1', $units);
        self::assertSame(6.0, $zero['average'], 'Comptée zéro avec son coefficient.');
        self::assertSame(['svt'], $zero['result']['extras']['not_evaluated'], 'Toujours signalée.');
    }

    public function testCompositionDeFinDAnneeAvecSonPoids(): void
    {
        $unit = self::withComposition();
        self::assertSame(13.0, self::harness([])->sequence('S6', [$unit])['average'], 'Poids des épreuves : 1 et 1.');

        $final = self::harness([SecondaryStrategy::OPTION_FINAL_EXAM => ['assessment_kinds' => ['composition'], 'weight' => 3.0]]);
        self::assertSame(14.5, $final->sequence('S6', [$unit])['average'], '(10 + 16 × 3) ÷ 4.');
    }

    /**
     * Mathématiques : un devoir à 10 puis la composition à 16, poids 1 chacun.
     *
     * @return Unit
     */
    private static function withComposition(): array
    {
        $entry = static fn (float $value, string $kind): array => ['value' => $value, 'max' => 20.0, 'weight' => 1.0, 'absence' => null, 'effect' => null, 'kind' => $kind, 'session' => 'normal'];

        return ['subject_id' => 'math', 'code' => 'MATH', 'name' => null, 'kind' => 'subject', 'coefficient' => 1.0, 'credits' => 0,
            'groups' => [['element_id' => null, 'code' => null, 'coefficient' => 1.0, 'credits' => 0, 'entries' => [$entry(10.0, 'devoir'), $entry(16.0, 'composition')]]]];
    }

    public function testOptionsMalFormeesRefuseesALaPublication(): void
    {
        $strategy = new SecondaryStrategy();
        self::assertSame([], $strategy->optionErrors(Defaults::STRATEGY_OPTIONS + [
            SecondaryStrategy::OPTION_COEFFICIENTS => ['m' => 2.0, 'p' => ['coefficient' => 1.0, 'counts_in_average' => false, 'role' => 'project']],
            SecondaryStrategy::OPTION_FINAL_EXAM => ['assessment_kinds' => ['composition'], 'weight' => 2.0],
        ]));
        foreach ([
            [SecondaryStrategy::OPTION_COEFFICIENTS => ['m' => -1.0]],
            [SecondaryStrategy::OPTION_COEFFICIENTS => ['m' => ['coefficient' => 1.0, 'counts_in_average' => 'non']]],
            [SecondaryStrategy::OPTION_COEFFICIENTS => 'm=2'],
            [SecondaryStrategy::OPTION_NOT_EVALUATED => 'ignore'],
            [SecondaryStrategy::OPTION_FINAL_EXAM => ['assessment_kinds' => [], 'weight' => 2.0]],
            [SecondaryStrategy::OPTION_FINAL_EXAM => ['assessment_kinds' => ['composition'], 'weight' => 0.0]],
        ] as $bad) {
            self::assertNotSame([], $strategy->optionErrors($bad + Defaults::STRATEGY_OPTIONS), (string) json_encode($bad));
        }
    }
}
