<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use DomainException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\ConductScale;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\SanctionProposal;

/**
 * TST-10 (T944), exemple E6 : barème d'exemple des heures d'absence injustifiées (RG-PRE-1) ; à 20 heures, blâme de conduite
 * PROPOSÉ, appliqué seulement après la décision d'une personne habilitée (RG-PRE-2). Les quatre paliers et la récidive.
 */
final class ConductScaleTest extends TestCase
{
    /** @return iterable<string, array{float, bool, string|null}> */
    public static function paliers(): iterable
    {
        yield 'sous le premier palier' => [9.5, false, null];
        yield '10 h avertissement' => [10.0, false, 'conduct_warning'];
        yield '19 h avertissement' => [19.0, false, 'conduct_warning'];
        yield '20 h blâme' => [20.0, false, 'conduct_blame'];
        yield '30 h exclusion temporaire' => [30.0, false, 'temporary_exclusion'];
        yield 'au-delà de 30 h conseil de discipline' => [30.5, false, 'discipline_council'];
        yield 'récidive conseil de discipline' => [12.0, true, 'discipline_council'];
    }

    /** @dataProvider paliers */
    public function testQuatrePaliersDuBaremeDExemple(float $hours, bool $repeat, ?string $expected): void
    {
        $proposal = (new ConductScale(Defaults::CONDUCT_SCALE))->propose('p1', $hours, $repeat);

        self::assertSame($expected, $proposal?->code);
        if ($proposal !== null) {
            self::assertSame(SanctionProposal::PROPOSED, $proposal->status);
            self::assertFalse($proposal->isApplied());
        }
    }

    public function testVingtHeuresBlameProposeAppliqueSeulementApresDecision(): void
    {
        $proposal = (new ConductScale(Defaults::CONDUCT_SCALE))->propose('p1', 20.0);
        self::assertNotNull($proposal);
        self::assertSame('Blâme de conduite', $proposal->label);
        self::assertSame('surveillant_general', $proposal->level);
        self::assertFalse($proposal->isApplied(), 'Proposée, pas appliquée.');

        $applied = $proposal->apply('surveillant-1');

        self::assertTrue($applied->isApplied());
        self::assertSame('surveillant-1', $applied->decidedBy);
        self::assertFalse($proposal->isApplied(), 'La proposition d\'origine est inchangée.');
        $this->expectException(DomainException::class);
        $applied->reject('chef-1', 'deuxième décision');
    }

    public function testRejetMotive(): void
    {
        $proposal = (new ConductScale(Defaults::CONDUCT_SCALE))->propose('p1', 10.0);
        self::assertNotNull($proposal);
        self::assertSame(SanctionProposal::REJECTED, $proposal->reject('surveillant-1', 'Absences justifiées après coup')->status);

        $this->expectException(DomainException::class);
        $proposal->reject('surveillant-1', ' ');
    }

    public function testBaremeVideAucuneProposition(): void
    {
        self::assertNull((new ConductScale([]))->propose('p1', 99.0));
    }
}
