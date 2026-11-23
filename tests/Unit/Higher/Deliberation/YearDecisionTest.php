<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Deliberation;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Deliberation\Application\Strategies\HigherLmdStrategy;
use Tests\Support\Higher\Deliberation\DeliberationFixtures as F;

/** Décisions de fin d'année LMD (CDC ; RG-INS-5, decisions C6) et F4. */
final class YearDecisionTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function year(int $s1, int $s2, array $context = []): array
    {
        $units = static fn (string $prefix, int $earned): array => [
            $prefix . 'A' => ['code' => $prefix . 'A', 'status' => $earned >= 15 ? 'acquired' : 'not_acquired'],
            $prefix . 'B' => ['code' => $prefix . 'B', 'status' => $earned >= 30 ? 'compensated' : 'not_acquired'],
        ];

        return (new HigherLmdStrategy())->decideYear(F::rules(), [
            ['period_id' => 'S1', 'average' => 11.0, 'credits_earned' => $s1, 'credits_total' => 30, 'units' => $units('S1', $s1)],
            ['period_id' => 'S2', 'average' => 12.0, 'credits_earned' => $s2, 'credits_total' => 30, 'units' => $units('S2', $s2)],
        ], $context);
    }

    public function testSoixanteCreditsAdmis(): void
    {
        $year = $this->year(30, 30);
        self::assertSame('Admis', $year['decision']);
        self::assertSame(11.5, $year['average'], 'F4 : (11 × 30 + 12 × 30) ÷ 60.');
        self::assertSame(60, $year['credits_earned']);
        self::assertSame('Passable', $year['extras']['mention'] ?? null);
    }

    public function testQuaranteCinqACinquanteNeufAdmisAvecDettes(): void
    {
        self::assertSame('Admis avec dettes', $this->year(30, 29)['decision']);
        $debts = $this->year(30, 15);
        self::assertSame('Admis avec dettes', $debts['decision']);
        self::assertSame(['S1A', 'S1B', 'S2A'], $debts['extras']['acquired_units'] ?? null, 'UE capitalisées (RG-INS-4).');
        self::assertSame(['S2B'], $debts['extras']['missing_units'] ?? null, 'UE manquantes inscrites d\'office.');
    }

    public function testQuaranteQuatreRedoubleAvecCapitalisation(): void
    {
        $year = $this->year(30, 14);
        self::assertSame('Redouble', $year['decision']);
        self::assertSame(['S1A', 'S1B'], $year['extras']['acquired_units'] ?? null);
    }

    public function testDeuxiemeRedoublementExcluSaufDerogation(): void
    {
        self::assertSame('Exclu pour insuffisance', $this->year(20, 20, ['enrolments_at_level' => 2])['decision']);
        self::assertSame('Redouble', $this->year(20, 20, ['enrolments_at_level' => 2, 'derogation' => true])['decision']);
        self::assertSame('Redouble', $this->year(20, 20, ['enrolments_at_level' => 1])['decision']);
    }

    public function testDerniereAnneeDeLicenceCentQuatreVingtsCredits(): void
    {
        $final = $this->year(30, 30, ['final_year' => true, 'cumulative_credits' => 120]);
        self::assertSame('Admis', $final['decision']);
        self::assertSame(180, $final['extras']['cumulative_credits'] ?? null);
        self::assertTrue($final['extras']['diploma_file'] ?? null);
        self::assertFalse($this->year(30, 30, ['final_year' => true, 'cumulative_credits' => 110])['extras']['diploma_file'] ?? null);
    }
}
