<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Enrolment;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\EnrolmentStatus;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\MatriculeFormat;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\ProgressionRules;
use Scholaris\Modules\Apps\Higher\Enrolment\Domain\RefundGrid;

final class EnrolmentDomainTest extends TestCase
{
    public function testFormatParDefautDuMatricule(): void
    {
        $format = new MatriculeFormat();

        self::assertSame('26EC-CF-0042', $format->format(2026, 'EC', 'cf', 42));
        self::assertSame('26EC-CF-#', $format->sequenceKey(2026, 'EC', 'CF'));
        self::assertSame('2026/EC/CF/000315', (new MatriculeFormat('{AAAA}/{ECOLE}/{FILIERE}/{N6}'))->format(2026, 'EC', 'CF', 315));
    }

    public function testFormatDeMatriculeInvalideRefuse(): void
    {
        foreach (['{AA}{ECOLE}', '{AA}-{N4}-{N4}', '{AA}{X}-{N4}'] as $pattern) {
            try {
                new MatriculeFormat($pattern);
                self::fail($pattern);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new MatriculeFormat())->format(2026, 'EC', 'CF', 10000);
    }

    public function testReinscriptionSelonLaDecisionDePassage(): void
    {
        self::assertNull(ProgressionRules::refusal('admitted', 1, 2));
        self::assertNull(ProgressionRules::refusal('admitted_with_debts', 1, 2));
        self::assertNull(ProgressionRules::refusal('repeat', 1, 1));
        self::assertNotNull(ProgressionRules::refusal('repeat', 1, 2));
        self::assertNotNull(ProgressionRules::refusal('excluded', 1, 1));
        self::assertNotNull(ProgressionRules::refusal('admitted', 1, 3));
        self::assertNotNull(ProgressionRules::refusal(null, 0, 2));
    }

    public function testGrilleDeRemboursement(): void
    {
        $grid = RefundGrid::fromSettings('2026-11-30', [['until' => '2026-11-30', 'percent' => 25], ['until' => '2026-10-31', 'percent' => 50]]);

        self::assertSame(60000, $grid->refundXaf('2026-10-13', 120000));
        self::assertSame(25, $grid->percentOn('2026-11-15'));
        self::assertSame(0, $grid->percentOn('2026-12-01'), 'Après la date limite : aucun remboursement (RG-INS-6).');
        self::assertSame(0, RefundGrid::fromSettings(null, [])->percentOn('2026-10-01'));
    }

    public function testTransitionsDeStatut(): void
    {
        self::assertTrue(EnrolmentStatus::canMove('enrolled', 'graduated'));
        self::assertTrue(EnrolmentStatus::canMove('suspended', 'enrolled'));
        self::assertFalse(EnrolmentStatus::canMove('withdrawn', 'enrolled'));
        self::assertFalse(EnrolmentStatus::canMove('pending', 'graduated'));
    }
}
