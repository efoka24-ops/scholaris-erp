<?php

declare(strict_types=1);

namespace Tests\Feature;

use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Tests\Support\AppTestCase;

final class AuditLogTest extends AppTestCase
{
    private AuditLog $audit;

    protected function setUp(): void
    {
        $this->boot();
        $this->audit = $this->kernel->container()->get(AuditLog::class);
    }

    public function testUneChaineVideOuIntacteEstValide(): void
    {
        self::assertTrue($this->audit->verify('t1')->intact);

        $this->audit->append('t1', 'alice', 'grade.updated', 'grade', 'g1', ['value' => 12], ['value' => 14], '10.0.0.1', 'Chrome');
        $this->audit->append('t1', 'alice', 'grade.locked', 'grade', 'g1');
        $result = $this->audit->verify('t1');

        self::assertTrue($result->intact);
        self::assertSame(2, $result->checked);
    }

    public function testLesRangsSontContinusParClient(): void
    {
        self::assertSame(1, $this->audit->append('t1', 'a', 'x', 'o', '1'));
        self::assertSame(2, $this->audit->append('t1', 'a', 'x', 'o', '2'));
        self::assertSame(1, $this->audit->append('t2', 'a', 'x', 'o', '1'));
    }

    public function testUneModificationDUneEntreeRompLaChaine(): void
    {
        $this->audit->append('t1', 'alice', 'payment.created', 'payment', 'p1', null, ['amount' => 1000]);
        $this->audit->append('t1', 'alice', 'payment.created', 'payment', 'p2', null, ['amount' => 2000]);
        $this->audit->append('t1', 'alice', 'payment.created', 'payment', 'p3', null, ['amount' => 3000]);

        $this->connections()->pdo()->exec('UPDATE audit_log SET after_json = \'{"amount":1}\' WHERE tenant_id = \'t1\' AND seq = 2');
        $result = $this->audit->verify('t1');

        self::assertFalse($result->intact);
        self::assertSame(2, $result->brokenAt);
        self::assertSame(1, $result->checked);
    }

    public function testLaSuppressionDUneEntreeEstDetectee(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->audit->append('t1', 'alice', 'x', 'o', $id);
        }
        $this->connections()->pdo()->exec('DELETE FROM audit_log WHERE tenant_id = \'t1\' AND seq = 2');
        $result = $this->audit->verify('t1');

        self::assertFalse($result->intact);
        self::assertSame(3, $result->brokenAt);
    }

    public function testLaChaineDUnClientNEstPasAffecteeParUnAutre(): void
    {
        $this->audit->append('t1', 'a', 'x', 'o', '1');
        $this->audit->append('t2', 'a', 'x', 'o', '1');
        $this->connections()->pdo()->exec('UPDATE audit_log SET action = \'forgé\' WHERE tenant_id = \'t2\'');

        self::assertTrue($this->audit->verify('t1')->intact);
        self::assertFalse($this->audit->verify('t2')->intact);
    }
}
