<?php

declare(strict_types=1);

namespace Tests\Acceptance\Secondary;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\Defaults;
use Scholaris\Modules\Apps\Secondary\Councils\Domain\PracticalGrid;

/**
 * T943, exemple E5 : grille pratique d'exemple 3 + 2 + 4 + 2 + 1 = 12 sur 20 ; la même grille avec un manquement grave à la
 * sécurité est plafonnée au plafond d'exemple (8). Critères, points, base et plafond sont des paramètres.
 */
final class PracticalGridTest extends TestCase
{
    private const OBSERVED = ['preparation' => 3, 'safety' => 2, 'execution' => 4, 'quality' => 2, 'time' => 1];

    public function testNoteSurVingt(): void
    {
        $result = PracticalGrid::fromParameters(Defaults::PRACTICAL_GRID)->score(self::OBSERVED);

        self::assertEqualsWithDelta(12.0, $result['score'], 1e-9);
        self::assertEqualsWithDelta(12.0, $result['observed'], 1e-9);
        self::assertEqualsWithDelta(20.0, $result['total'], 1e-9);
        self::assertFalse($result['capped']);
    }

    public function testManquementGravePlafonne(): void
    {
        $result = PracticalGrid::fromParameters(Defaults::PRACTICAL_GRID)->score(self::OBSERVED, true);

        self::assertEqualsWithDelta(8.0, $result['score'], 1e-9);
        self::assertEqualsWithDelta(12.0, $result['uncapped'], 1e-9);
        self::assertTrue($result['capped']);
    }

    public function testGrilleDeLEtablissement(): void
    {
        $grid = new PracticalGrid([['code' => 'a', 'points' => 10], ['code' => 'b', 'points' => 30]], 20.0);

        self::assertEqualsWithDelta(15.0, $grid->score(['a' => 10, 'b' => 20], true)['score'], 1e-9, 'Sans plafond paramétré, aucun plafond.');
        $this->expectException(InvalidArgumentException::class);
        $grid->score(['a' => 11]);
    }
}
