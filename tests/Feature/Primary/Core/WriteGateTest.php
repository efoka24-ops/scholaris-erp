<?php

declare(strict_types=1);

namespace Tests\Feature\Primary\Core;

use PHPUnit\Framework\TestCase;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Primary\Support\WriteGate;
use Scholaris\Modules\Socle\Platform\Contracts\ApplicationAccess;
use Tests\Support\Primary\FakeApplicationAccess;

final class WriteGateTest extends TestCase
{
    public function testTrialAndActiveAllowWrites(): void
    {
        $access = new FakeApplicationAccess();
        $gate = new WriteGate($access);
        foreach ([ApplicationAccess::TRIAL, ApplicationAccess::ACTIVE, ApplicationAccess::PAST_DUE] as $state) {
            $access->set('tenant-a', $state);
            $gate->assertWritable('tenant-a');
            self::assertTrue($access->allowsWrites('tenant-a', 'primary'));
        }
    }

    public function testSuspendedAllowsReadsButReturns423OnWrites(): void
    {
        $access = new FakeApplicationAccess();
        $access->set('tenant-a', ApplicationAccess::SUSPENDED);
        $gate = new WriteGate($access);
        $gate->assertReadable('tenant-a');
        try {
            $gate->assertWritable('tenant-a');
            self::fail('Écriture suspendue acceptée.');
        } catch (HttpException $exception) {
            self::assertSame(423, $exception->status);
        }
    }

    public function testClosedApplicationReturns403OnWrite(): void
    {
        $access = new FakeApplicationAccess();
        $access->set('tenant-a', ApplicationAccess::CLOSED);
        try {
            (new WriteGate($access))->assertWritable('tenant-a');
            self::fail('Écriture fermée acceptée.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
        }
    }

    public function testNeverOpenedCannotWrite(): void
    {
        try {
            (new WriteGate(new FakeApplicationAccess()))->assertWritable('tenant-a');
            self::fail('Client non ouvert accepté.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
        }
    }
}
