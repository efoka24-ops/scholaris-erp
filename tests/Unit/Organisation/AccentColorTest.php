<?php

declare(strict_types=1);

namespace Tests\Unit\Organisation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Organisation\Domain\AccentColor;

final class AccentColorTest extends TestCase
{
    public function testLaCouleurParDefautRespecteLeContraste(): void
    {
        self::assertSame('#F26B1D', AccentColor::default()->hex());
        self::assertGreaterThanOrEqual(4.5, AccentColor::default()->contrastWithBlack());
        self::assertSame('#F26B1D', AccentColor::parse('#f26b1d')->hex(), 'La casse est normalisée.');
    }

    public function testValeursLimitesDuContraste(): void
    {
        // Gris #757575 : 4,56:1 avec le noir (accepté) ; #747474 : 4,49:1 (refusé).
        self::assertSame('#757575', AccentColor::parse('#757575')->hex());
        self::assertGreaterThanOrEqual(4.5, AccentColor::parse('#757575')->contrastWithBlack());

        try {
            AccentColor::parse('#747474');
            self::fail('#747474 doit être refusé.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('4,49:1', $e->getMessage());
            self::assertStringContainsString('au moins 4,5:1', $e->getMessage());
        }
    }

    public function testNoirRefuseEtBlancAccepte(): void
    {
        self::assertEqualsWithDelta(21.0, AccentColor::parse('#FFFFFF')->contrastWithBlack(), 0.001);

        $this->expectException(InvalidArgumentException::class);
        AccentColor::parse('#000000');
    }

    /** @return array<string, array{string}> */
    public static function formatsInvalides(): array
    {
        return [
            'sans dièse' => ['F26B1D'],
            'trois chiffres' => ['#FFF'],
            'huit chiffres' => ['#F26B1DFF'],
            'caractère non hexadécimal' => ['#GGGGGG'],
            'vide' => [''],
            'nom de couleur' => ['orange'],
            'injection' => ['#F26B1D;background:url(x)'],
        ];
    }

    #[DataProvider('formatsInvalides')]
    public function testFormatInvalideRefuse(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#RRGGBB');
        AccentColor::parse($value);
    }
}
