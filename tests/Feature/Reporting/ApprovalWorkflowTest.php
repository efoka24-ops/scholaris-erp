<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use LogicException;
use PHPUnit\Framework\TestCase;
use Scholaris\Modules\Socle\Reporting\Contracts\ApprovalWorkflow;

final class ApprovalWorkflowTest extends TestCase
{
    public function testLeWorkflowEnchaineDesEtapesFourniesParLAppelant(): void
    {
        $workflow = new FixtureApprovalWorkflow();
        $workflow->submit('tenant-fixture', 'census-fixture', 'submitter-fixture', ['school-fixture', 'region-fixture']);

        self::assertSame('school-fixture', $workflow->activeStep('tenant-fixture', 'census-fixture'));
        self::assertSame('region-fixture', $workflow->approve('tenant-fixture', 'census-fixture', 'school-fixture', 'approver-fixture'));
        self::assertNull($workflow->approve('tenant-fixture', 'census-fixture', 'region-fixture', 'approver-fixture'));
    }

    public function testUnSnapshotGeleNePeutPasEtreRemplace(): void
    {
        $workflow = new FixtureApprovalWorkflow();
        $snapshot = ['counts' => ['school-fixture' => 8]];
        $workflow->freezeSnapshot('tenant-fixture', 'census-fixture', 'version-fixture', $snapshot);
        $workflow->freezeSnapshot('tenant-fixture', 'census-fixture', 'version-fixture', $snapshot);

        self::assertSame(['version' => 'version-fixture', 'payload' => $snapshot], $workflow->frozenSnapshot('tenant-fixture', 'census-fixture'));

        $this->expectException(LogicException::class);
        $workflow->freezeSnapshot('tenant-fixture', 'census-fixture', 'version-fixture', ['counts' => ['school-fixture' => 9]]);
    }

    public function testUnRetourReprendLeWorkflowAuPremierNiveauFourni(): void
    {
        $workflow = new FixtureApprovalWorkflow();
        $workflow->submit('tenant-fixture', 'census-fixture', 'submitter-fixture', ['school-fixture', 'region-fixture']);
        $workflow->approve('tenant-fixture', 'census-fixture', 'school-fixture', 'approver-fixture');
        $workflow->returnForRevision('tenant-fixture', 'census-fixture', 'region-fixture', 'reviewer-fixture', 'motif-fixture');

        self::assertSame('school-fixture', $workflow->activeStep('tenant-fixture', 'census-fixture'));
    }
}

/** Implémentation synthétique de test ; aucun rôle ni circuit métier officiel n'est fixé. */
final class FixtureApprovalWorkflow implements ApprovalWorkflow
{
    /** @var array<string, list<string>> */
    private array $steps = [];

    /** @var array<string, int> */
    private array $activeIndexes = [];

    /** @var array<string, array{version: string, payload: array<string, mixed>}> */
    private array $snapshots = [];

    public function submit(string $tenantId, string $workflowId, string $submittedBy, array $steps): void
    {
        $key = $tenantId . ':' . $workflowId;
        $this->steps[$key] = $steps;
        $this->activeIndexes[$key] = 0;
    }

    public function activeStep(string $tenantId, string $workflowId): ?string
    {
        $key = $tenantId . ':' . $workflowId;
        $index = $this->activeIndexes[$key] ?? 0;

        return $this->steps[$key][$index] ?? null;
    }

    public function approve(string $tenantId, string $workflowId, string $stepId, string $actorId): ?string
    {
        if ($this->activeStep($tenantId, $workflowId) !== $stepId) {
            throw new LogicException('Seule l’étape active du workflow peut être validée.');
        }

        $key = $tenantId . ':' . $workflowId;
        $this->activeIndexes[$key]++;

        return $this->activeStep($tenantId, $workflowId);
    }

    public function returnForRevision(string $tenantId, string $workflowId, string $stepId, string $actorId, string $reason): void
    {
        if ($this->activeStep($tenantId, $workflowId) !== $stepId) {
            throw new LogicException('Seule l’étape active du workflow peut retourner la soumission.');
        }

        $this->activeIndexes[$tenantId . ':' . $workflowId] = 0;
    }

    /** @param array<string, mixed> $payload */
    public function freezeSnapshot(string $tenantId, string $workflowId, string $version, array $payload): void
    {
        $key = $tenantId . ':' . $workflowId;
        $snapshot = ['version' => $version, 'payload' => $payload];
        if (isset($this->snapshots[$key]) && $this->snapshots[$key] !== $snapshot) {
            throw new LogicException('Un snapshot gelé ne peut pas être remplacé.');
        }
        $this->snapshots[$key] = $snapshot;
    }

    public function frozenSnapshot(string $tenantId, string $workflowId): ?array
    {
        return $this->snapshots[$tenantId . ':' . $workflowId] ?? null;
    }
}

