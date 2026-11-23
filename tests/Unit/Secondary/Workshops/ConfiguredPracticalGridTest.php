<?php

declare(strict_types=1);

namespace Tests\Unit\Secondary\Workshops;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Workshops\Application\ConfiguredPracticalGrid;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalGrid;

/**
 * T909, T943 par le contrat PracticalGrid de Workshops : grille d'exemple du CDC (4/4/6/4/2), 3 + 2 + 4 + 2 + 1 = 12/20, plafond
 * d'exemple 8 en cas de manquement grave. Toutes ces valeurs sont fournies par le test.
 */
final class ConfiguredPracticalGridTest extends TestCase
{
    private const CRITERIA = [
        ['code' => 'preparation', 'label' => 'Préparation', 'points' => 4], ['code' => 'safety', 'label' => 'Sécurité', 'points' => 4],
        ['code' => 'execution', 'label' => 'Exécution', 'points' => 6], ['code' => 'quality', 'label' => 'Qualité', 'points' => 4],
        ['code' => 'time', 'label' => 'Temps', 'points' => 2],
    ];
    private const OBSERVED = ['preparation' => 3, 'safety' => 2, 'execution' => 4, 'quality' => 2, 'time' => 1];

    private PracticalGrid $grid;

    protected function setUp(): void
    {
        $this->grid = new ConfiguredPracticalGrid();
    }

    public function testGrilleDExempleDouzeSurVingt(): void
    {
        $result = $this->grid->score(self::CRITERIA, 20.0, 8.0, self::OBSERVED, false);

        self::assertSame(12.0, $result['score']);
        self::assertSame(20.0, $result['total']);
        self::assertFalse($result['capped']);
    }

    public function testManquementGravePlafonneAuPlafondDeLaFiche(): void
    {
        $result = $this->grid->score(self::CRITERIA, 20.0, 8.0, self::OBSERVED, true);

        self::assertSame(8.0, $result['score']);
        self::assertSame(12.0, $result['uncapped']);
        self::assertTrue($result['capped']);
        self::assertSame(12.0, $this->grid->score(self::CRITERIA, 20.0, null, self::OBSERVED, true)['score'], 'Sans plafond paramétré, aucun plafond.');
    }

    public function testBaseParametreeEtTotalDifferent(): void
    {
        $criteria = [['code' => 'a', 'label' => 'A', 'points' => 5], ['code' => 'b', 'label' => 'B', 'points' => 5]];

        self::assertSame(15.0, $this->grid->score($criteria, 20.0, null, ['a' => 5, 'b' => 2.5], false)['score'], '7,5 sur 10 ramené sur 20.');
        self::assertSame(7.5, $this->grid->score($criteria, 10.0, null, ['a' => 5, 'b' => 2.5], false)['score']);
    }

    public function testPointsHorsLimitesRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grid->score(self::CRITERIA, 20.0, null, ['preparation' => 5], false);
    }

    public function testCritereInconnuRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grid->score(self::CRITERIA, 20.0, null, ['inconnu' => 1], false);
    }

    public function testPlafondAuDelaDeLaBaseRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->grid->score(self::CRITERIA, 20.0, 21.0, [], false);
    }
}
