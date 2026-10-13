<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Support\Ulid;

final class UlidTest extends TestCase
{
    public function testFormatDe26CaracteresValides(): void
    {
        $id = (string) new Ulid();

        self::assertSame(26, strlen($id));
        self::assertTrue(Ulid::isValid($id));
        self::assertSame($id, (new Ulid())->toBase32() === $id ? $id : $id);
    }

    public function testLesIdentifiantsSontUniquesEtOrdonnes(): void
    {
        $ids = [];
        for ($i = 0; $i < 5000; $i++) {
            $ids[] = (string) new Ulid();
        }

        self::assertCount(5000, array_unique($ids));
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($ids, $sorted);
    }

    public function testValeurExistanteEstConserveeEnMajuscules(): void
    {
        self::assertSame('01J9T0000000000000000SCH01', (string) new Ulid('01j9t0000000000000000sch01'));
    }

    public function testValeurInvalideEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ulid('pas-un-ulid');
    }

    public function testRefuseUnDebordementDHorodatage(): void
    {
        self::assertFalse(Ulid::isValid('ZZZZZZZZZZZZZZZZZZZZZZZZZZ'));
        self::assertFalse(Ulid::isValid('01J9T0000000000000000SCH0I'));
    }
}
