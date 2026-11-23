<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\PromotionPolicy;
use Tests\Support\Secondary\StrategyHarness;

/**
 * TST-03 (T942), exemple E3 « Brice T. », 3e année électricité d'un CETIC, jeu `example_cdc` : total 221,50 ÷ 22 = 10,07 ; la
 * pratique professionnelle (7,75) est sous le seuil d'exemple 8 : alerte ; en fin d'année, refus d'admission proposé malgré une
 * moyenne générale au-dessus de 10, passage conditionnel possible.
 */
final class BriceCetic3Test extends TestCase
{
    public const SUBJECTS = [
        'fr' => [2, 11, 10, 10.50], 'en' => [2, 10, 9, 9.50], 'math' => [3, 9, 11, 10.00], 'sp' => [2, 10, 10.5, 10.25],
        'tech' => [4, 12, 11.5, 11.75], 'dessin' => [3, 13, 12, 12.50], 'atelier' => [6, 7, 8.5, 7.75],
    ];

    /** @return array{0: StrategyHarness, 1: array<string, mixed>} */
    private static function trimester(): array
    {
        $harness = new StrategyHarness();

        return [$harness, AichaTrimesterTest::trimester($harness, self::SUBJECTS, 'subject', ['atelier'])];
    }

    public function testMoyenneEtAlertePratique(): void
    {
        [, $t1] = self::trimester();

        $total = 0.0;
        foreach (self::SUBJECTS as $id => [$coefficient, , , $expected]) {
            self::assertEqualsWithDelta($expected, $t1['units'][$id]['average'], 1e-9, $id);
            $total += $t1['units'][$id]['average'] * $coefficient;
        }
        self::assertEqualsWithDelta(221.50, $total, 1e-9);
        self::assertSame(10.07, $t1['average']);
        self::assertSame(['atelier'], $t1['result']['extras']['practical_alerts']);
        self::assertTrue($t1['units']['atelier']['practical']);
        self::assertNull($t1['result']['extras']['work_proposal'], '10,07 : aucune proposition du travail.');
    }

    public function testRefusProposeEnFinDAnneeAvecPassageConditionnelPossible(): void
    {
        [$harness, $t1] = self::trimester();
        $t2 = AichaTrimesterTest::trimester($harness, self::SUBJECTS, 'subject', ['atelier']);
        $t3 = AichaTrimesterTest::trimester($harness, self::SUBJECTS, 'subject', ['atelier']);
        $t2['period_id'] = 'T2';
        $t3['period_id'] = 'T3';

        $year = $harness->aggregate('Y', [$t1, $t2, $t3]);

        self::assertSame(10.07, $year['average']);
        self::assertEqualsWithDelta(7.75, $year['units']['atelier']['average'], 1e-9);
        $promotion = $year['result']['extras']['promotion'];
        self::assertSame(PromotionPolicy::PRACTICAL_REFUSED, $promotion['code'], 'Quelle que soit la moyenne générale.');
        self::assertTrue($promotion['conditional_possible']);
        self::assertTrue($promotion['practical_below']);
        self::assertSame(PromotionPolicy::PRACTICAL_REFUSED, $harness->decideYear([$t1, $t2, $t3])['extras']['promotion']['code']);
    }

    public function testPratiqueAuSeuilAdmis(): void
    {
        $subjects = self::SUBJECTS;
        $subjects['atelier'] = [6, 8, 8, 8.0];
        $harness = new StrategyHarness();
        $trimesters = [];
        foreach (['T1', 'T2', 'T3'] as $id) {
            $trimester = AichaTrimesterTest::trimester($harness, $subjects, 'subject', ['atelier']);
            $trimester['period_id'] = $id;
            $trimesters[] = $trimester;
        }

        $year = $harness->aggregate('Y', $trimesters);

        self::assertSame([], $year['result']['extras']['practical_alerts']);
        self::assertSame(PromotionPolicy::ADMITTED, $year['result']['extras']['promotion']['code']);
    }

    public function testEnseignementGeneralSansPratique(): void
    {
        $harness = new StrategyHarness();
        $t1 = AichaTrimesterTest::trimester($harness, self::SUBJECTS);

        self::assertFalse($t1['units']['atelier']['practical'], 'Aucune matière de pratique sans le type « practical ».');
        self::assertSame([], $t1['result']['extras']['practical_alerts']);
    }
}
