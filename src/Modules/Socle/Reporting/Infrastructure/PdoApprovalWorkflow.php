<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\RateLimit\Clock;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Reporting\Contracts\ApprovalWorkflow;

/**
 * Circuit de validation séquentiel (PP5, EX-STA-03) : les étapes sont fournies par l'appelant, chacune est validée dans l'ordre,
 * un retour motivé arrête le circuit (il se soumet de nouveau depuis la première étape), le snapshot est gelé après la dernière
 * validation. Toute correction ultérieure crée une version n+1 qui DOIT porter `payload['reason']` (RG-STA-1) ; la version gelée
 * n'est jamais réécrite. Chaque décision est écrite dans le journal d'audit.
 */
final class PdoApprovalWorkflow implements ApprovalWorkflow
{
    private const IN_REVIEW = 'in_review';
    private const RETURNED = 'returned';
    private const APPROVED = 'approved';

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public function submit(string $tenantId, string $workflowId, string $submittedBy, array $steps): void
    {
        $this->assertId($workflowId);
        if ($steps === [] || count($steps) > 20 || count(array_unique($steps)) !== count($steps)) {
            throw HttpException::validation(['steps' => ['Liste ordonnée de 1 à 20 étapes distinctes requise.']]);
        }
        foreach ($steps as $step) {
            if ($step === '' || mb_strlen($step) > 60) {
                throw HttpException::validation(['steps' => ['Identifiant d\'étape invalide (60 caractères au plus).']]);
            }
        }
        $now = $this->now();
        $pdo = $this->connections->pdo();
        $current = $this->workflow($tenantId, $workflowId);
        if ($current !== null && $current['status'] !== self::RETURNED) {
            throw HttpException::conflict('Ce circuit est déjà soumis.');
        }
        $pdo->beginTransaction();
        try {
            if ($current === null) {
                $pdo->prepare('INSERT INTO reporting_workflows (id, tenant_id, workflow_id, status, submitted_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([(string) new Ulid(), $tenantId, $workflowId, self::IN_REVIEW, $submittedBy, $now, $now]);
            } else {
                $pdo->prepare('UPDATE reporting_workflows SET status = ?, submitted_by = ?, updated_at = ? WHERE tenant_id = ? AND workflow_id = ?')
                    ->execute([self::IN_REVIEW, $submittedBy, $now, $tenantId, $workflowId]);
                $pdo->prepare('DELETE FROM reporting_workflow_steps WHERE tenant_id = ? AND workflow_id = ?')->execute([$tenantId, $workflowId]);
            }
            foreach ($steps as $position => $step) {
                $pdo->prepare('INSERT INTO reporting_workflow_steps (id, tenant_id, workflow_id, position, step_id, status) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([(string) new Ulid(), $tenantId, $workflowId, $position + 1, $step, 'pending']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->audit->append($tenantId, $submittedBy, 'workflow.submitted', 'workflow', $workflowId, null, ['steps' => $steps, 'resubmitted' => $current !== null]);
    }

    public function activeStep(string $tenantId, string $workflowId): ?string
    {
        $workflow = $this->workflow($tenantId, $workflowId);
        if ($workflow === null || $workflow['status'] !== self::IN_REVIEW) {
            return null;
        }

        return $this->pendingStep($tenantId, $workflowId)['step_id'] ?? null;
    }

    public function approve(string $tenantId, string $workflowId, string $stepId, string $actorId): ?string
    {
        $active = $this->activeRow($tenantId, $workflowId, $stepId);
        $now = $this->now();
        $this->connections->pdo()->prepare("UPDATE reporting_workflow_steps SET status = 'approved', actor_id = ?, decided_at = ? WHERE tenant_id = ? AND workflow_id = ? AND position = ?")
            ->execute([$actorId, $now, $tenantId, $workflowId, $active['position']]);
        $next = $this->pendingStep($tenantId, $workflowId);
        if ($next === null) {
            $this->setStatus($tenantId, $workflowId, self::APPROVED, $now);
        }
        $this->audit->append($tenantId, $actorId, 'workflow.step_approved', 'workflow', $workflowId, null, ['step' => $stepId, 'completed' => $next === null]);

        return $next['step_id'] ?? null;
    }

    public function returnForRevision(string $tenantId, string $workflowId, string $stepId, string $actorId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw HttpException::validation(['reason' => ['Un motif de 1 à 500 caractères est obligatoire pour un renvoi.']]);
        }
        $active = $this->activeRow($tenantId, $workflowId, $stepId);
        $now = $this->now();
        $this->connections->pdo()->prepare("UPDATE reporting_workflow_steps SET status = 'returned', actor_id = ?, reason = ?, decided_at = ? WHERE tenant_id = ? AND workflow_id = ? AND position = ?")
            ->execute([$actorId, $reason, $now, $tenantId, $workflowId, $active['position']]);
        $this->setStatus($tenantId, $workflowId, self::RETURNED, $now);
        $this->audit->append($tenantId, $actorId, 'workflow.returned', 'workflow', $workflowId, null, ['step' => $stepId, 'reason' => $reason]);
    }

    public function freezeSnapshot(string $tenantId, string $workflowId, string $version, array $payload): void
    {
        $workflow = $this->workflow($tenantId, $workflowId) ?? throw HttpException::notFound('Circuit introuvable.');
        if ($workflow['status'] !== self::APPROVED) {
            throw HttpException::conflict('Le snapshot ne se gèle qu\'après la dernière validation.');
        }
        if ($version === '' || mb_strlen($version) > 40) {
            throw HttpException::validation(['version' => ['Version requise (40 caractères au plus).']]);
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo = $this->connections->pdo();
        $statement = $pdo->prepare('SELECT version, payload_json FROM reporting_workflow_snapshots WHERE tenant_id = ? AND workflow_id = ? ORDER BY frozen_at, id');
        $statement->execute([$tenantId, $workflowId]);
        $existing = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($existing as $row) {
            if ($row['version'] === $version) {
                if ($row['payload_json'] === $json) {
                    return;
                }
                throw HttpException::conflict('Cette version est gelée : enregistrez une nouvelle version motivée.');
            }
        }
        $reason = $payload['reason'] ?? null;
        if ($existing !== [] && (!is_string($reason) || trim($reason) === '')) {
            throw HttpException::validation(['payload.reason' => ['Une correction après gel exige un motif (payload.reason).']]);
        }
        $now = $this->now();
        $pdo->prepare('INSERT INTO reporting_workflow_snapshots (id, tenant_id, workflow_id, version, payload_json, frozen_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(string) new Ulid(), $tenantId, $workflowId, $version, $json, $now]);
        $this->audit->append($tenantId, null, 'workflow.snapshot_frozen', 'workflow', $workflowId, null, ['version' => $version, 'correction' => $existing !== []]);
    }

    public function frozenSnapshot(string $tenantId, string $workflowId): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT version, payload_json FROM reporting_workflow_snapshots WHERE tenant_id = ? AND workflow_id = ? ORDER BY frozen_at DESC, id DESC LIMIT 1');
        $statement->execute([$tenantId, $workflowId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $payload = json_decode((string) $row['payload_json'], true);

        return ['version' => (string) $row['version'], 'payload' => is_array($payload) ? $payload : []];
    }

    /** @return array<string, mixed>|null */
    private function workflow(string $tenantId, string $workflowId): ?array
    {
        $statement = $this->connections->pdo()->prepare('SELECT * FROM reporting_workflows WHERE tenant_id = ? AND workflow_id = ? LIMIT 1');
        $statement->execute([$tenantId, $workflowId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function pendingStep(string $tenantId, string $workflowId): ?array
    {
        $statement = $this->connections->pdo()->prepare("SELECT * FROM reporting_workflow_steps WHERE tenant_id = ? AND workflow_id = ? AND status = 'pending' ORDER BY position LIMIT 1");
        $statement->execute([$tenantId, $workflowId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> étape active, ou 404 (circuit inconnu) / 409 (circuit non en cours, étape qui n'est pas la courante) */
    private function activeRow(string $tenantId, string $workflowId, string $stepId): array
    {
        $workflow = $this->workflow($tenantId, $workflowId) ?? throw HttpException::notFound('Circuit introuvable.');
        if ($workflow['status'] !== self::IN_REVIEW) {
            throw HttpException::conflict('Ce circuit n\'est pas en cours de validation.');
        }
        $step = $this->pendingStep($tenantId, $workflowId);
        if ($step === null || $step['step_id'] !== $stepId) {
            throw HttpException::conflict('Cette étape n\'est pas l\'étape en cours.');
        }

        return $step;
    }

    private function setStatus(string $tenantId, string $workflowId, string $status, string $now): void
    {
        $this->connections->pdo()->prepare('UPDATE reporting_workflows SET status = ?, updated_at = ? WHERE tenant_id = ? AND workflow_id = ?')
            ->execute([$status, $now, $tenantId, $workflowId]);
    }

    private function assertId(string $workflowId): void
    {
        if ($workflowId === '' || mb_strlen($workflowId) > 80) {
            throw HttpException::validation(['workflow_id' => ['Identifiant de circuit requis (80 caractères au plus).']]);
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new DateTimeZone('Africa/Douala'))->format(DATE_ATOM);
    }
}
