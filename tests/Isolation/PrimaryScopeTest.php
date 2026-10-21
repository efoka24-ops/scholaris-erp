<?php

declare(strict_types=1);

namespace Tests\Isolation;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Primary\Support\Perimeter;
use Scholaris\Modules\Apps\Primary\Support\ScopedByClass;

final class PrimaryScopeTest extends TestCase
{
    public function testOtherEstablishmentClassIsHiddenByClassScope(): void
    {
        $scope = new ScopedByClass();
        $perimeter = new Perimeter('tenant-a', 'school-a', false, ['class-a']);
        $scope->assertReadable($perimeter, ['class_id' => 'class-a']);
        try {
            $scope->assertReadable($perimeter, ['class_id' => 'class-b']);
            self::fail('Classe hors périmètre visible.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testClassFilterIsBoundAndEmptyScopeRefusesAll(): void
    {
        $scope = new ScopedByClass();
        self::assertSame(['class_id IN (?, ?)', ['one', 'two']], $scope->sqlFilter(new Perimeter('tenant-a', 'school-a', false, ['one', 'two'])));
        self::assertSame(['1 = 0', []], $scope->sqlFilter(new Perimeter('tenant-a', 'school-a', false)));
    }

    public function testWholeEstablishmentScopeIsExplicitlyUnbounded(): void
    {
        self::assertSame(['1 = 1', []], (new ScopedByClass())->sqlFilter(new Perimeter('tenant-a', 'school-a', true)));
    }
}
