<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Modules\Socle\Reporting\Contracts\ApprovalWorkflow;
use Tests\Support\AppTestCase;
use Tests\Support\Organisation\FixedClock;

/** PP5 : circuit de validation en 5 niveaux, renvoi motivé, gel, correction après gel = version n+1 motivée (RG-STA-1). */
final class PdoApprovalWorkflowTest extends AppTestCase
{
    private const STEPS = ['director', 'inspector', 'department', 'region', 'planning'];

    protected function setUp(): void
    {
        $this->boot([Clock::class => new FixedClock()]);
    }

    public function testLesCinqEtapesSeValidentDansLOrdreEtLeSnapshotSeGele(): void
    {
        $flow = $this->flow();
        $flow->submit('t1', 'stats-2026', 'director-user', self::STEPS);

        self::assertSame('director', $flow->activeStep('t1', 'stats-2026'));
        self::assertSame('inspector', $flow->approve('t1', 'stats-2026', 'director', 'director-user'));
        self::assertSame('department', $flow->approve('t1', 'stats-2026', 'inspector', 'inspector-user'));
        self::assertSame('region', $flow->approve('t1', 'stats-2026', 'department', 'dept-user'));
        self::assertSame('planning', $flow->approve('t1', 'stats-2026', 'region', 'region-user'));
        self::assertNull($flow->approve('t1', 'stats-2026', 'planning', 'plan-user'));
        self::assertNull($flow->activeStep('t1', 'stats-2026'));

        $flow->freezeSnapshot('t1', 'stats-2026', '1', ['pupils' => 420]);
        self::assertSame(['version' => '1', 'payload' => ['pupils' => 420]], $flow->frozenSnapshot('t1', 'stats-2026'));
    }

    public function testRenvoiMotiveAuNiveau2ArreteLeCircuitEtLeRelanceDepuisLeDebut(): void
    {
        $flow = $this->flow();
        $flow->submit('t1', 'stats-2026', 'director-user', self::STEPS);
        $flow->approve('t1', 'stats-2026', 'director', 'director-user');

        $this->expectStatus(422, fn () => $flow->returnForRevision('t1', 'stats-2026', 'inspector', 'inspector-user', '  '));
        $flow->returnForRevision('t1', 'stats-2026', 'inspector', 'inspector-user', 'Effectifs CE2 incohérents');
        self::assertNull($flow->activeStep('t1', 'stats-2026'));
        $this->expectStatus(409, fn () => $flow->approve('t1', 'stats-2026', 'inspector', 'inspector-user'));

        $flow->submit('t1', 'stats-2026', 'director-user', self::STEPS);
        self::assertSame('director', $flow->activeStep('t1', 'stats-2026'));
        $reasons = $this->connections()->pdo()->query("SELECT after_json FROM audit_log WHERE action = 'workflow.returned'")->fetchAll();
        self::assertCount(1, $reasons);
        self::assertStringContainsString('Effectifs CE2 incohérents', (string) $reasons[0]['after_json']);
    }

    public function testUneEtapeHorsOrdreOuUnCircuitNonEnCoursEstRefuse(): void
    {
        $flow = $this->flow();
        $flow->submit('t1', 'stats-2026', 'u', self::STEPS);

        $this->expectStatus(409, fn () => $flow->approve('t1', 'stats-2026', 'region', 'u'));
        $this->expectStatus(409, fn () => $flow->submit('t1', 'stats-2026', 'u', self::STEPS));
        $this->expectStatus(404, fn () => $flow->approve('t1', 'inconnu', 'director', 'u'));
        $this->expectStatus(404, fn () => $flow->approve('t2', 'stats-2026', 'director', 'u'));
        $this->expectStatus(422, fn () => $flow->submit('t1', 'autre', 'u', []));
        $this->expectStatus(422, fn () => $flow->submit('t1', 'autre', 'u', ['a', 'a']));
        $this->expectStatus(409, fn () => $flow->freezeSnapshot('t1', 'stats-2026', '1', ['x' => 1]));
    }

    public function testCorrectionApresGelCreeUneVersion2MotiveeSansReecrireLaVersion1(): void
    {
        $flow = $this->flow();
        $flow->submit('t1', 'stats-2026', 'u', ['only']);
        $flow->approve('t1', 'stats-2026', 'only', 'u');
        $flow->freezeSnapshot('t1', 'stats-2026', '1', ['pupils' => 420]);

        $flow->freezeSnapshot('t1', 'stats-2026', '1', ['pupils' => 420]);
        $this->expectStatus(409, fn () => $flow->freezeSnapshot('t1', 'stats-2026', '1', ['pupils' => 421]));
        $this->expectStatus(422, fn () => $flow->freezeSnapshot('t1', 'stats-2026', '2', ['pupils' => 421]));
        $flow->freezeSnapshot('t1', 'stats-2026', '2', ['pupils' => 421, 'reason' => 'Erreur de saisie CM1']);

        $latest = $flow->frozenSnapshot('t1', 'stats-2026');
        self::assertSame('2', $latest['version']);
        self::assertSame(421, $latest['payload']['pupils']);
        self::assertSame(2, (int) $this->connections()->pdo()->query("SELECT COUNT(*) FROM reporting_workflow_snapshots WHERE workflow_id = 'stats-2026'")->fetchColumn());
        self::assertNull($flow->frozenSnapshot('t2', 'stats-2026'));
    }

    private function flow(): ApprovalWorkflow
    {
        return $this->kernel->container()->get(ApprovalWorkflow::class);
    }

    private function expectStatus(int $status, callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);

            return;
        }
        self::fail('HttpException ' . $status . ' attendue.');
    }
}
