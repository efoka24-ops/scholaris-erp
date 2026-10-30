<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Billing\Domain\Amounts;
use Scholaris\Modules\Socle\Billing\Domain\Numbers;

final class AmountsTest extends TestCase
{
    public function testBourseDeMeriteDe20PourCentSurLaPension(): void
    {
        self::assertSame(90000, Amounts::discount('percent', 20, 450000));
        self::assertSame([30000, 30000, 30000], Amounts::spread(90000, [150000, 150000, 150000]));
    }

    public function testLaRemiseEstReparSurLesTranchesRestantesSansPerdreUnXaf(): void
    {
        $shares = Amounts::spread(100, [33, 33, 34]);

        self::assertSame(100, array_sum($shares));
        // Une tranche déjà soldée (reste 0) ne reçoit rien.
        self::assertSame([0, 50, 50], Amounts::spread(100, [0, 100, 100]));
        // La remise est plafonnée au restant dû.
        self::assertSame([10, 20], Amounts::spread(500, [10, 20]));
    }

    public function testRemiseFixePlafonneeEtPourcentageEntier(): void
    {
        self::assertSame(25000, Amounts::discount('fixed', 25000, 300000));
        self::assertSame(100, Amounts::discount('fixed', 500, 100));
        self::assertSame(333, Amounts::discount('percent', 10, 3333), 'Le pourcentage est arrondi à l\'entier inférieur.');
    }

    public function testPourcentageSuperieurA100Refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amounts::discount('percent', 101, 1000);
    }

    public function testDecoupageEnTranchesEgales(): void
    {
        self::assertSame([120000, 120000, 120000], Amounts::split(360000, 3));
        self::assertSame([34, 33, 33], Amounts::split(100, 3));
    }

    public function testFormatsDeNumeros(): void
    {
        self::assertSame('R-IPES-26-04821', Numbers::receipt('IPES', 2026, 4821));
        self::assertSame('FAM-0587', Numbers::familyReference(587));
        self::assertSame('IPES', Numbers::codeFromName('Ipes'));
        self::assertSame('CLP', Numbers::codeFromName('Collège Le Pivot'));
    }
}
