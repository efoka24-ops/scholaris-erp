<?php

declare(strict_types=1);

namespace Tests\Unit\Staff;

use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Staff\Domain\VacationCalc;

final class VacationCalcTest extends TestCase
{
    public function testExempleDuCahierDesChargesDonne43Virgule5HetdEt261000Francs(): void
    {
        // 18 h CM + 12 h TD + 6 h TP, taux 6 000 FCFA par HETD
        $hetd = VacationCalc::hetdMilli(['cm' => 18 * 60, 'td' => 12 * 60, 'tp' => 6 * 60]);

        self::assertSame(43500, $hetd);
        self::assertSame(261000, VacationCalc::amount($hetd, 6000));
    }

    public function testLesCoefficientsFournisRemplacentLesDefautsNatureParNature(): void
    {
        // variante du CDC : CM à 2 HETD ; TD et TP gardent leur défaut
        $hetd = VacationCalc::hetdMilli(['cm' => 18 * 60, 'td' => 12 * 60, 'tp' => 6 * 60], ['cm' => 2000]);

        self::assertSame(52500, $hetd);
        self::assertSame(315000, VacationCalc::amount($hetd, 6000));
        self::assertSame(43500, VacationCalc::hetdMilli(['cm' => 18 * 60, 'td' => 12 * 60, 'tp' => 6 * 60], ['cm' => 1500, 'td' => 1000, 'tp' => 750]));
    }

    public function testUneSeanceAutreCompteCommeUnTd(): void
    {
        self::assertSame(2000, VacationCalc::hetdMilli(['other' => 120]));
    }

    public function testLeMontantEstUnEntierArrondiAuFranc(): void
    {
        // 50 minutes de CM = 1,25 HETD ; 1 250 × 3 333 / 1 000 = 4 166,25 -> 4 166
        $hetd = VacationCalc::hetdMilli(['cm' => 50]);

        self::assertSame(1250, $hetd);
        self::assertSame(4166, VacationCalc::amount($hetd, 3333));
    }

    public function testAucuneHeureAucunMontant(): void
    {
        self::assertSame(0, VacationCalc::hetdMilli([]));
        self::assertSame(0, VacationCalc::amount(0, 6000));
    }

    public function testBornesDUnMois(): void
    {
        self::assertSame(['2026-02-01', '2026-02-28'], VacationCalc::monthBounds('2026-02'));
        self::assertSame(['2028-02-01', '2028-02-29'], VacationCalc::monthBounds('2028-02'));
    }

    public function testUneEcheanceInvalideEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VacationCalc::monthBounds('2026-13');
    }
}
