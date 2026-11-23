<?php

declare(strict_types=1);

namespace Tests\Unit\Higher\Academic;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Higher\Academic\Domain\ExpiryThresholds;

/** EX-ACA-07 : seuils 90/30/7 jours, un envoi par seuil, pas de rafale, rien après l'échéance. */
final class ExpiryThresholdsTest extends TestCase
{
    public function testJoursRestants(): void
    {
        self::assertSame(90, ExpiryThresholds::daysBetween('2026-10-13', '2027-01-11'));
        self::assertSame(0, ExpiryThresholds::daysBetween('2027-01-11', '2027-01-11'));
        self::assertSame(-1, ExpiryThresholds::daysBetween('2027-01-12', '2027-01-11'));
        self::assertSame(151, ExpiryThresholds::daysBetween('2027-02-01', '2027-07-02'));
    }

    public function testSeuilDu(): void
    {
        $t = new ExpiryThresholds();

        self::assertSame([90, 30, 7], $t->days);
        self::assertNull($t->due(91, []));
        self::assertSame([90, [90]], $t->due(90, []));
        self::assertNull($t->due(60, [90]));
        self::assertSame([30, [30]], $t->due(30, [90]));
        self::assertSame([7, [90, 30, 7]], $t->due(5, []), 'Reprise après interruption : un seul envoi, au plus petit seuil.');
        self::assertNull($t->due(5, [7]));
        self::assertSame([7, [7]], $t->due(0, [90, 30]), 'Le jour même de l\'échéance.');
        self::assertNull($t->due(-1, []));
    }

    public function testSeuilsParametresNettoyes(): void
    {
        self::assertSame([60, 15], (new ExpiryThresholds([15, 'x', 60, -3, 15]))->days);
        self::assertSame([90, 30, 7], (new ExpiryThresholds(['invalide']))->days);
    }
}
