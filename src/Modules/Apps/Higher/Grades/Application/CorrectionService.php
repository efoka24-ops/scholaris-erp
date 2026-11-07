<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;
use Scholaris\Modules\Apps\Higher\Grades\Domain\MarkValue;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;

/**
 * Correction d'une note verrouillée (EX-NOT-06, RG-NOT-2, API-12) : demande motivée avec pièce par l'enseignant affecté, puis chaîne
 * de validations `assessment.correction.chain` (chef de département, puis directeur d'école avec 2FA). Le demandeur ne valide
 * jamais ; une même personne ne valide pas deux étapes. La note d'origine n'est jamais écrasée sans procédure : la correction
 * approuvée crée une nouvelle version liée (historique avec auteur, motif, pièce et validations).
 */
final class CorrectionService
{
    public const TABLE = 'hi_grade_corrections';
    public const ATTACHMENT_AREA = 'higher-grades';

    public function __construct(
        private readonly AttachmentStore $attachments,
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly AssessmentService $assessments,
        private readonly MarkService $marks,
        private readonly GradesSettings $settings,
        private readonly RecentSecondFactor $secondFactor,
        private readonly GradesClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $v score|absence, reason, attachment_ref (nom affiché ou référence papier), query_id?
     * @param string|null $file contenu de la pièce téléversée (contrôlée et stockée par AttachmentStore), sinon null
     * @return array<string, mixed>
     */
    public function request(Principal $principal, string $establishmentId, string $markId, array $v, ?string $file = null): array
    {
        $tenantId = $principal->tenantId;
        $mark = $this->store->findOrFail(AssessmentService::MARKS, $tenantId, $establishmentId, $markId, 'Note introuvable.');
        $row = $this->assessments->find($tenantId, $establishmentId, (string) $mark['assessment_id']);
        $this->policy->assertTeacher($principal, $establishmentId, $row);
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id']);
        $locked = in_array(IpesDefaults::LOCKED, $lifecycle->states, true) ? $lifecycle->reached((string) $row['status'], IpesDefaults::LOCKED) : !$lifecycle->isEditable((string) $row['status']);
        if (!$locked) {
            throw HttpException::conflict('La note n\'est pas verrouillée : corrigez-la dans la grille tant que la saisie est ouverte.');
        }
        try {
            $value = $this->marks->value($tenantId, $establishmentId, $row, $v);
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation(['score' => [$e->getMessage()]]);
        }
        if ($value->isEmpty()) {
            throw HttpException::validation(['score' => ['Nouvelle note ou code d\'absence attendu.']]);
        }
        if ($this->store->count(self::TABLE, $tenantId, $establishmentId, ['mark_id' => $markId, 'status' => 'pending']) > 0) {
            throw HttpException::conflict('Une demande de correction est déjà en cours pour cette note.');
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $stored = null;
        if ($file !== null) {
            try {
                $stored = $this->attachments->store($tenantId, self::ATTACHMENT_AREA, $id, $file);
            } catch (\InvalidArgumentException $e) {
                throw HttpException::validation(['attachment' => [$e->getMessage()]]);
            }
        }
        $reference = trim((string) ($v['attachment_ref'] ?? ''));
        if ($stored !== null && $reference === '') {
            $reference = 'piece-' . strtolower($id) . '.' . $stored->extension;
        }
        try {
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => $id, 'mark_id' => $markId, 'assessment_id' => $row['id'], 'person_id' => $mark['person_id'],
                'school_id' => $row['school_id'], 'department_id' => $row['department_id'], 'program_id' => $row['program_id'],
                'old_score' => $mark['score'], 'old_absence' => $mark['absence_code'], 'new_score' => $value->score, 'new_absence' => $value->absence,
                'reason' => (string) $v['reason'], 'attachment_ref' => $reference, 'query_id' => $v['query_id'] ?? null,
                'attachment_path' => $stored?->path, 'attachment_mime' => $stored?->mime, 'attachment_size' => $stored?->size,
                'requested_by' => $principal->userId, 'status' => 'pending', 'approval_step' => 0, 'approval_trail' => '[]', 'decided_at' => null, 'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->attachments->delete($tenantId, $stored->path);
            }

            throw $e;
        }
        $v['attachment_ref'] = $reference;
        $this->policy->audit($principal, 'higher.grades.correction.requested', 'higher_grade_correction', $id, ['score' => $mark['score'], 'absence' => $mark['absence_code']], [
            'score' => $value->score, 'absence' => $value->absence, 'reason' => $v['reason'], 'attachment_ref' => $v['attachment_ref'],
            'attachment' => $stored === null ? null : ['mime' => $stored->mime, 'size' => $stored->size],
        ]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function approve(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        [$correction, $row, $step, $chain, $trail] = $this->decidable($principal, $establishmentId, $id);
        $trail[] = ['step' => $step, 'label' => $chain[$step]['label'], 'decision' => 'approved', 'by' => $principal->userId, 'at' => $this->clock->now()];
        $last = $step + 1 >= count($chain);
        $this->store->transaction(function () use ($principal, $establishmentId, $tenantId, $correction, $row, $step, $trail, $last, $id): void {
            $this->store->update(self::TABLE, $tenantId, $establishmentId, [
                'approval_step' => $step + 1, 'approval_trail' => (string) json_encode($trail), 'status' => $last ? 'approved' : 'pending',
                'decided_at' => $last ? $this->clock->now() : null,
            ], ['id' => $id, 'approval_step' => $step]);
            if ($last) {
                $mark = $this->store->findOrFail(AssessmentService::MARKS, $tenantId, $establishmentId, (string) $correction['mark_id']);
                $value = MarkValue::of($correction['new_score'] === null ? null : (float) $correction['new_score'], $correction['new_absence'], (float) $row['max_score'], (float) $row['score_step'], $this->settings->absenceCodes($tenantId, $establishmentId, (string) $row['school_id']));
                $this->marks->apply($principal, $establishmentId, $row, $mark, $value, 'correction', null, (string) $correction['reason'] . ' [pièce : ' . $correction['attachment_ref'] . ']', $id);
            }
        });
        $this->policy->audit($principal, 'higher.grades.correction.' . ($last ? 'approved' : 'step_approved'), 'higher_grade_correction', $id, null, ['step' => $step + 1]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function reject(Principal $principal, string $establishmentId, string $id, string $reason): array
    {
        $tenantId = $principal->tenantId;
        [, , $step, $chain, $trail] = $this->decidable($principal, $establishmentId, $id);
        $trail[] = ['step' => $step, 'label' => $chain[$step]['label'], 'decision' => 'rejected', 'reason' => $reason, 'by' => $principal->userId, 'at' => $this->clock->now()];
        $this->store->update(self::TABLE, $tenantId, $establishmentId, [
            'approval_trail' => (string) json_encode($trail), 'status' => 'rejected', 'decided_at' => $this->clock->now(),
        ], ['id' => $id]);
        $this->policy->audit($principal, 'higher.grades.correction.rejected', 'higher_grade_correction', $id, null, ['step' => $step, 'reason' => $reason]);

        return $this->show($tenantId, $establishmentId, $id);
    }

    /**
     * Corrections visibles : demandes de l'enseignant, ou toutes celles du périmètre d'un validateur.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function visible(Principal $principal, string $establishmentId, array $filters): array
    {
        $tenantId = $principal->tenantId;
        $out = [];
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, $filters, 'created_at DESC', 500) as $row) {
            if ($row['requested_by'] === $principal->userId || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::CORRECTION_APPROVE)
                || $this->policy->covers($principal, $establishmentId, $row, MarkPolicy::READ[0])) {
                $out[] = $this->resource($tenantId, $establishmentId, $row);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function find(Principal $principal, string $establishmentId, string $id): array
    {
        return $this->resource($principal->tenantId, $establishmentId, $this->visibleRow($principal, $establishmentId, $id));
    }

    /**
     * Pièce téléversée d'une demande, pour les seuls rôles qui voient la demande (sinon 404, comme pour un autre client).
     *
     * @return array{content: string, mime: string, filename: string}
     */
    public function attachment(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->visibleRow($principal, $establishmentId, $id);
        $path = $row['attachment_path'] ?? null;
        $content = is_string($path) && $path !== '' ? $this->attachments->read($principal->tenantId, $path) : null;
        if ($content === null) {
            throw HttpException::notFound('Aucune pièce téléversée pour cette demande.');
        }
        $this->policy->audit($principal, 'higher.grades.correction.attachment_downloaded', 'higher_grade_correction', $id, null, null);

        return ['content' => $content, 'mime' => (string) $row['attachment_mime'], 'filename' => 'correction-' . strtolower($id) . '.' . pathinfo((string) $path, PATHINFO_EXTENSION)];
    }

    /** @return array<string, mixed> demande visible : demandeur, validateur ou lecteur des notes dans le périmètre, sinon 404 */
    private function visibleRow(Principal $principal, string $establishmentId, string $id): array
    {
        $row = $this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, $id, 'Demande de correction introuvable.');
        if ($row['requested_by'] !== $principal->userId && !$this->policy->covers($principal, $establishmentId, $row, MarkPolicy::CORRECTION_APPROVE)
            && !$this->policy->covers($principal, $establishmentId, $row, MarkPolicy::READ[0])) {
            throw HttpException::notFound('Demande de correction introuvable.');
        }

        return $row;
    }

    /**
     * Contrôles d'une décision : demande en attente, étape courante, permission et rôle de l'étape dans le périmètre, ni demandeur
     * ni validateur d'une étape précédente, 2FA si l'étape l'exige.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: list<array{permission: string, role: ?string, label: string, require_recent_2fa: bool}>, 4: list<array<string, mixed>>}
     */
    private function decidable(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $correction = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Demande de correction introuvable.');
        if ($correction['status'] !== 'pending') {
            throw HttpException::conflict('Demande déjà décidée.');
        }
        $row = $this->assessments->find($tenantId, $establishmentId, (string) $correction['assessment_id']);
        $chain = $this->settings->correctionChain($tenantId, $establishmentId, (string) $row['school_id']);
        $step = (int) $correction['approval_step'];
        if (!isset($chain[$step])) {
            throw HttpException::conflict('Chaîne de validation incohérente pour cette demande.');
        }
        $definition = $chain[$step];
        $perimeter = $this->policy->assertScoped($principal, $establishmentId, $row, $definition['permission'], 'higher_grade_correction');
        if ($definition['role'] !== null && !in_array($definition['role'], $perimeter->roles, true)) {
            $this->policy->audit($principal, 'higher.grades.denied', 'higher_grade_correction', $id, null, ['step' => $step, 'role_required' => $definition['role']]);

            throw HttpException::forbidden('Cette étape (' . $definition['label'] . ') revient à un autre rôle.');
        }
        $trail = json_decode((string) ($correction['approval_trail'] ?? '[]'), true);
        $trail = is_array($trail) ? array_values($trail) : [];
        if ($correction['requested_by'] === $principal->userId) {
            throw HttpException::forbidden('Séparation des tâches : vous ne pouvez pas valider votre propre demande de correction.');
        }
        foreach ($trail as $entry) {
            if (is_array($entry) && ($entry['by'] ?? null) === $principal->userId) {
                throw HttpException::forbidden('Vous avez déjà validé une étape de cette demande : une autre personne doit valider la suivante.');
            }
        }
        if ($definition['require_recent_2fa'] && !$this->secondFactor->isRecent($tenantId, $principal->userId)) {
            throw new HttpException(403, 'Double authentification requise', 'Ressaisissez votre code de double authentification pour cette validation.', LifecycleService::SECOND_FACTOR_TYPE);
        }

        return [$correction, $row, $step, $chain, $trail];
    }

    /** @return array<string, mixed> */
    private function show(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->resource($tenantId, $establishmentId, $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function resource(string $tenantId, string $establishmentId, array $row): array
    {
        $assessment = $this->store->find(AssessmentService::TABLE, $tenantId, $establishmentId, (string) $row['assessment_id']);
        $chain = $assessment === null ? [] : $this->settings->correctionChain($tenantId, $establishmentId, (string) $assessment['school_id']);
        $step = (int) $row['approval_step'];

        return [
            'id' => (string) $row['id'], 'mark_id' => (string) $row['mark_id'], 'assessment_id' => (string) $row['assessment_id'],
            'element_code' => $assessment['element_code'] ?? null, 'assessment_label' => $assessment['label'] ?? null,
            'person_id' => (string) $row['person_id'],
            'old' => ['score' => $row['old_score'] === null ? null : (float) $row['old_score'], 'absence' => $row['old_absence']],
            'new' => ['score' => $row['new_score'] === null ? null : (float) $row['new_score'], 'absence' => $row['new_absence']],
            'reason' => (string) $row['reason'], 'attachment_ref' => (string) $row['attachment_ref'], 'query_id' => $row['query_id'],
            'attachment' => ($row['attachment_path'] ?? null) === null ? null : [
                'mime' => (string) $row['attachment_mime'], 'size' => (int) $row['attachment_size'],
                'download_path' => '/higher/grade-corrections/' . $row['id'] . '/attachment',
            ],
            'requested_by' => (string) $row['requested_by'], 'status' => (string) $row['status'], 'approval_step' => $step,
            'next_step' => $row['status'] === 'pending' && isset($chain[$step]) ? ['label' => $chain[$step]['label'], 'role' => $chain[$step]['role'], 'require_recent_2fa' => $chain[$step]['require_recent_2fa']] : null,
            'steps' => array_map(static fn (array $s): string => $s['label'], $chain),
            'approval_trail' => json_decode((string) ($row['approval_trail'] ?? '[]'), true) ?: [],
            'decided_at' => $row['decided_at'], 'created_at' => (string) $row['created_at'],
        ];
    }
}
