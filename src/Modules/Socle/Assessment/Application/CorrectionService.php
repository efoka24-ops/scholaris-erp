<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use InvalidArgumentException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;
use Scholaris\Modules\Socle\Audit\Contracts\Approvals;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Correction d'une note verrouillée (T119) : demande motivée, approuvée par une AUTRE personne (Audit\Contracts\Approvals).
 * Tant que l'approbation manque, la note ne change pas. L'application de la correction écrit l'historique de la note.
 *
 * Approbation à n niveaux (S3, paramètre assessment.correction.chain) : chaque étape a son droit (chef de département
 * puis directeur d'école, par exemple) et sa 2FA éventuelle ; chaque étape est une demande d'approbation chaînée ; une
 * même personne n'approuve jamais deux étapes ; la note ne change qu'à la dernière. Défaut : une seule étape.
 */
final class CorrectionService
{
    public function __construct(
        private readonly Db $db,
        private readonly GradeRepository $grades,
        private readonly GradeService $gradeService,
        private readonly AssessmentService $assessments,
        private readonly ScopeGuard $scope,
        private readonly ResultService $results,
        private readonly Approvals $approvals,
        private readonly AuditLog $audit,
        private readonly AssessmentClock $clock,
        private readonly AssessmentSettings $settings,
        private readonly PermissionChecker $permissions,
        private readonly RecentSecondFactor $secondFactor,
    ) {
    }

    /**
     * @param array<string, mixed> $data grade_id, value|absence, reason
     * @return array<string, mixed>
     */
    public function request(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $grade = $this->grades->grade($tenantId, $establishmentId, (string) $data['grade_id']) ?? throw HttpException::notFound('Note introuvable.');
        $assessment = $this->assessments->assessment($tenantId, $establishmentId, (string) $grade['assessment_id']);
        if ($this->isEditable($tenantId, $establishmentId, $assessment)) {
            throw HttpException::conflict('Cette épreuve n\'est pas encore verrouillée : modifiez la note directement dans la grille.');
        }
        $this->scope->assertCanEnter($principal, $establishmentId, $assessment, [(string) $grade['person_id']]);
        if ($this->grades->pendingCorrectionOf($tenantId, (string) $grade['id']) !== null) {
            throw HttpException::conflict('Une demande de correction est déjà en attente pour cette note.');
        }

        try {
            $cell = $this->gradeService->parseCell($assessment, $data);
        } catch (InvalidArgumentException $e) {
            throw HttpException::validation(['value' => [$assessment['title'] . ' : ' . $e->getMessage() . ' Corrigez avant d\'envoyer la demande.']]);
        }
        if ($this->same($grade, $cell)) {
            throw HttpException::conflict('La valeur demandée est déjà celle de la note. Aucune correction n\'est nécessaire.');
        }

        return $this->db->transaction(function () use ($principal, $establishmentId, $tenantId, $grade, $assessment, $cell, $data): array {
            $approvalId = $this->approvals->request($tenantId, 'grade.correction', 'grade', (string) $grade['id'], (string) $data['reason'], $principal->userId);
            $id = (string) new Ulid();
            $this->grades->insert('grade_corrections', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'grade_id' => $grade['id'], 'assessment_id' => $assessment['id'],
                'person_id' => $grade['person_id'], 'new_score' => $cell['score'], 'new_absence' => $cell['absence'], 'reason' => $data['reason'],
                'status' => 'pending', 'approval_id' => $approvalId, 'requested_by' => $principal->userId, 'decided_by' => null,
                'created_at' => $this->clock->now(), 'decided_at' => null, 'approval_step' => 0, 'approval_trail' => '[]',
            ]);
            $this->audit->append($tenantId, $principal->userId, 'grade.correction.requested', 'grade', (string) $grade['id'], null, ['correction_id' => $id, 'reason' => $data['reason']]);

            return $this->find($tenantId, $establishmentId, $id);
        });
    }

    /**
     * Droits qui permettent d'agir sur une correction (approuver ou rejeter une étape).
     *
     * @return list<string>
     */
    public function approverPermissions(string $tenantId, string $establishmentId): array
    {
        return array_values(array_unique(array_column($this->settings->correctionChain($tenantId, $establishmentId), 'permission')));
    }

    /** @return array<string, mixed> */
    public function approve(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $correction = $this->pending($tenantId, $establishmentId, $id);
        if ($correction['requested_by'] === $principal->userId) {
            throw HttpException::forbidden('La correction doit être approuvée par une autre personne que celle qui l\'a demandée.');
        }
        $chain = $this->settings->correctionChain($tenantId, $establishmentId);
        $index = min((int) ($correction['approval_step'] ?? 0), count($chain) - 1);
        $step = $chain[$index];
        $this->assertStep($principal, $establishmentId, $step);
        $trail = $this->trail($correction);
        if (in_array($principal->userId, array_column($trail, 'user_id'), true)) {
            throw HttpException::forbidden('Vous avez déjà approuvé une étape de cette correction : l\'étape suivante revient à une autre personne.');
        }

        return $this->db->transaction(function () use ($principal, $establishmentId, $tenantId, $correction, $id, $chain, $index, $step, $trail): array {
            $this->approvals->approve($tenantId, (string) $correction['approval_id'], $principal->userId);
            $now = $this->clock->now();
            $trail[] = ['step' => $index + 1, 'label' => $step['label'], 'user_id' => $principal->userId, 'approval_id' => (string) $correction['approval_id'], 'decided_at' => $now];

            if ($index + 1 < count($chain)) {
                $next = $this->approvals->request($tenantId, 'grade.correction', 'grade', (string) $correction['grade_id'], (string) $correction['reason'], (string) $correction['requested_by']);
                $this->grades->updateCorrection($tenantId, $id, [
                    'approval_step' => $index + 1, 'approval_id' => $next, 'approval_trail' => json_encode($trail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                $this->audit->append($tenantId, $principal->userId, 'grade.correction.step_approved', 'grade', (string) $correction['grade_id'], null, ['correction_id' => $id, 'step' => $index + 1, 'label' => $step['label']]);

                return $this->find($tenantId, $establishmentId, $id);
            }

            $grade = $this->grades->grade($tenantId, $establishmentId, (string) $correction['grade_id']) ?? throw HttpException::notFound('Note introuvable.');
            $assessment = $this->assessments->assessment($tenantId, $establishmentId, (string) $grade['assessment_id']);
            $new = ['score' => $correction['new_score'] === null ? null : (float) $correction['new_score'], 'absence' => $correction['new_absence'] === null ? null : (string) $correction['new_absence']];

            $this->grades->updateGrade($tenantId, (string) $grade['id'], [
                'score' => $new['score'], 'absence' => $new['absence'], 'version' => (int) $grade['version'] + 1, 'updated_at' => $now,
            ]);
            $this->gradeService->recordEvent(
                $tenantId,
                $establishmentId,
                (string) $grade['id'],
                (string) $grade['assessment_id'],
                (string) $grade['person_id'],
                'correction',
                $grade,
                $new,
                (string) $correction['requested_by'],
                (string) $correction['reason'],
                $principal->userId,
                $now,
            );
            $this->grades->updateCorrection($tenantId, $id, [
                'status' => 'approved', 'decided_by' => $principal->userId, 'decided_at' => $now, 'approval_step' => count($chain),
                'approval_trail' => json_encode($trail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $this->audit->append($tenantId, $principal->userId, 'grade.corrected', 'grade', (string) $grade['id'], ['score' => $grade['score'], 'absence' => $grade['absence']], $new + ['requested_by' => $correction['requested_by']]);
            $this->results->refreshIfComputed($tenantId, $establishmentId, (string) $assessment['structure_id'], (string) $assessment['period_id']);

            return $this->find($tenantId, $establishmentId, $id);
        });
    }

    /** @return array<string, mixed> */
    public function reject(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $correction = $this->pending($tenantId, $establishmentId, $id);
        $chain = $this->settings->correctionChain($tenantId, $establishmentId);
        $this->assertStep($principal, $establishmentId, $chain[min((int) ($correction['approval_step'] ?? 0), count($chain) - 1)], false);

        return $this->db->transaction(function () use ($principal, $establishmentId, $tenantId, $correction, $id): array {
            $this->approvals->reject($tenantId, (string) $correction['approval_id'], $principal->userId);
            $this->grades->updateCorrection($tenantId, $id, ['status' => 'rejected', 'decided_by' => $principal->userId, 'decided_at' => $this->clock->now()]);
            $this->audit->append($tenantId, $principal->userId, 'grade.correction.rejected', 'grade', (string) $correction['grade_id'], null, ['correction_id' => $id]);

            return $this->find($tenantId, $establishmentId, $id);
        });
    }

    /** @return array<string, mixed> demande avec l'étape en cours et les étapes franchies */
    public function find(string $tenantId, string $establishmentId, string $id): array
    {
        $row = $this->grades->correction($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Demande de correction introuvable.');
        $chain = $this->settings->correctionChain($tenantId, $establishmentId);
        $row['steps_total'] = count($chain);
        $row['current_step'] = $row['status'] === 'pending' ? $chain[min((int) ($row['approval_step'] ?? 0), count($chain) - 1)]['label'] : null;
        $row['approval_trail'] = $this->trail($row);

        return $row;
    }

    /** @param array{permission: string, label: string, require_recent_2fa: bool} $step */
    private function assertStep(Principal $principal, string $establishmentId, array $step, bool $approving = true): void
    {
        if (!$this->permissions->can($principal, $step['permission'], $establishmentId)) {
            throw HttpException::forbidden(sprintf('L\'étape « %s » de cette correction revient à une autre personne : vous n\'en avez pas le droit.', $step['label']));
        }
        if ($approving && $step['require_recent_2fa'] && !$this->secondFactor->isRecent($principal->tenantId, $principal->userId)) {
            throw HttpException::forbidden('Cette étape exige une double authentification récente. Confirmez votre second facteur puis recommencez.');
        }
    }

    /**
     * @param array<string, mixed> $correction
     * @return list<array<string, mixed>>
     */
    private function trail(array $correction): array
    {
        $raw = $correction['approval_trail'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    private function pending(string $tenantId, string $establishmentId, string $id): array
    {
        $correction = $this->grades->correction($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Demande de correction introuvable.');
        if ($correction['status'] !== 'pending') {
            throw HttpException::conflict('Cette demande a déjà été traitée.');
        }

        return $correction;
    }

    /** @param array<string, mixed> $assessment */
    private function isEditable(string $tenantId, string $establishmentId, array $assessment): bool
    {
        try {
            $this->assessments->assertEditable($tenantId, $establishmentId, $assessment, 'corriger');

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $grade
     * @param array{score: float|null, absence: string|null, qualitative: string|null, remark: string|null} $cell
     */
    private function same(array $grade, array $cell): bool
    {
        $score = $grade['score'] === null ? null : (float) $grade['score'];
        $sameScore = $score === null || $cell['score'] === null ? $score === $cell['score'] : abs($score - $cell['score']) < 1e-9;

        return $sameScore && ($grade['absence'] ?? null) === $cell['absence'];
    }
}
