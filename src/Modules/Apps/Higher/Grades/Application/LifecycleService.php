<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\Higher\Academic\Infrastructure\Store;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;

/**
 * Cycle de la saisie (EX-NOT-05, API-10, API-11) déclaré par `assessment.lifecycle` : brouillon → soumise (enseignant affecté)
 * → validée (chef de département) → verrouillée (chef de département, 2FA récente) → publiée (scolarité, 2FA, après PV signé).
 * Séparation des tâches : qui a saisi une note de l'évaluation ne la verrouille pas (`assessment.segregate_lock`).
 * Publication : chaque garde enregistrée (Deliberation, RG-DEL-3) peut refuser ; sans garde, refus (aucun PV signé possible).
 */
final class LifecycleService
{
    public const SECOND_FACTOR_TYPE = 'https://api.scholaris.cm/errors/second-factor-required';

    public function __construct(
        private readonly Store $store,
        private readonly MarkPolicy $policy,
        private readonly AssessmentService $assessments,
        private readonly GradesSettings $settings,
        private readonly RecentSecondFactor $secondFactor,
        private readonly PublicationGuards $guards,
        private readonly GradesClock $clock,
    ) {
    }

    /**
     * @param string|null $reason motif, obligatoire pour un renvoi à l'enseignant (retour à l'état initial) ; gardé dans l'historique
     * @return array<string, mixed>
     */
    public function transition(Principal $principal, string $establishmentId, string $assessmentId, string $target, ?int $expectedVersion = null, ?string $reason = null): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->assessments->find($tenantId, $establishmentId, $assessmentId);
        $schoolId = (string) $row['school_id'];
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId, $schoolId);
        $from = (string) $row['status'];
        $transition = $lifecycle->transition($from, $target);
        if ($transition === null) {
            $this->policy->assertReadable($principal, $establishmentId, $row);

            throw HttpException::conflict(sprintf('Passage impossible de « %s » à « %s ».', $from, $target));
        }
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($target === $lifecycle->initial() && $from !== $target && $reason === null) {
            throw HttpException::validation(['reason' => ['Motif obligatoire pour renvoyer la saisie à l\'enseignant.']]);
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $row['version']) {
            throw HttpException::conflict('L\'évaluation a changé depuis votre lecture : rechargez-la.');
        }
        $permission = $transition['permission'];
        if (in_array($permission, [MarkPolicy::SUBMIT, MarkPolicy::ENTER], true)) {
            $this->policy->assertTeacher($principal, $establishmentId, $row, $permission);
        } else {
            $perimeter = $this->policy->assertScoped($principal, $establishmentId, $row, $permission);
            // Circuit de validation réglé par l'administrateur : la fonction choisie peut exiger un rôle précis (le droit seul
            // est partagé par plusieurs rôles). Sans réglage, `role` est absent et seul le droit compte, comme avant.
            $role = $transition['role'] ?? null;
            if ($role !== null && !in_array($role, $perimeter->roles, true)) {
                $this->policy->audit($principal, 'higher.grades.denied', 'higher_grade_assessment', $assessmentId, null, ['permission' => $permission, 'role_required' => $role]);

                throw HttpException::forbidden('Cette étape revient à un autre rôle (circuit de validation de l\'établissement).');
            }
        }
        if ($transition['require_recent_2fa'] && !$this->secondFactor->isRecent($tenantId, $principal->userId)) {
            throw new HttpException(403, 'Double authentification requise', 'Ressaisissez votre code de double authentification pour cette opération.', self::SECOND_FACTOR_TYPE);
        }
        if (in_array($target, $this->settings->segregatedTargets($tenantId, $establishmentId, $schoolId), true) && $this->enteredBy($tenantId, $establishmentId, $assessmentId, $principal->userId)) {
            $this->policy->audit($principal, 'higher.grades.segregation_refused', 'higher_grade_assessment', $assessmentId, null, ['target' => $target]);

            throw HttpException::forbidden('Séparation des tâches : vous avez saisi des notes de cette évaluation, vous ne pouvez pas la verrouiller.');
        }
        if ($from === $lifecycle->initial() && $target !== $from) {
            $missing = $this->emptyCells($tenantId, $establishmentId, $row);
            if ($missing > 0) {
                throw HttpException::validation(['marks' => [sprintf('%d cellule(s) vide(s) : saisissez une note ou un code d\'absence pour chaque étudiant.', $missing)]]);
            }
        }
        if ($lifecycle->isPublished($target)) {
            $this->assertPublishable($tenantId, $establishmentId, $row);
        }
        $trail = json_decode((string) ($row['status_trail'] ?? '[]'), true);
        $trail = is_array($trail) ? $trail : [];
        $trail[] = ['from' => $from, 'to' => $target, 'by' => $principal->userId, 'at' => $this->clock->now()] + ($reason === null ? [] : ['reason' => $reason]);
        $updated = $this->store->update(AssessmentService::TABLE, $tenantId, $establishmentId, [
            'status' => $target, 'status_trail' => (string) json_encode($trail), 'version' => (int) $row['version'] + 1, 'updated_at' => $this->clock->now(),
        ], ['id' => $assessmentId, 'version' => (int) $row['version']]);
        if ($updated !== 1) {
            throw HttpException::conflict('L\'évaluation a changé pendant l\'opération : rechargez-la.');
        }
        $this->policy->audit($principal, 'higher.grades.assessment.' . $target, 'higher_grade_assessment', $assessmentId, ['status' => $from], ['status' => $target] + ($reason === null ? [] : ['reason' => $reason]));

        return $this->assessments->find($tenantId, $establishmentId, $assessmentId);
    }

    /**
     * Levée de l'anonymat par le service des examens (EX-NOT-04, permission dédiée), après la saisie.
     *
     * @return array<string, mixed>
     */
    public function liftAnonymity(Principal $principal, string $establishmentId, string $assessmentId): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->assessments->find($tenantId, $establishmentId, $assessmentId);
        $this->policy->assertReadable($principal, $establishmentId, $row);
        $this->policy->assertScoped($principal, $establishmentId, $row, MarkPolicy::LIFT);
        if ((int) $row['anonymous'] !== 1 || $row['anonymity_lifted_at'] !== null) {
            throw HttpException::conflict('Évaluation non anonyme ou anonymat déjà levé.');
        }
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId, (string) $row['school_id']);
        if ($lifecycle->isEditable((string) $row['status'])) {
            throw HttpException::conflict('L\'anonymat se lève après la soumission de la saisie.');
        }
        $now = $this->clock->now();
        $this->store->update(AssessmentService::TABLE, $tenantId, $establishmentId, [
            'anonymity_lifted_at' => $now, 'anonymity_lifted_by' => $principal->userId, 'version' => (int) $row['version'] + 1, 'updated_at' => $now,
        ], ['id' => $assessmentId]);
        $this->policy->audit($principal, 'higher.grades.anonymity_lifted', 'higher_grade_assessment', $assessmentId, null, ['lifted_at' => $now]);

        return $this->assessments->find($tenantId, $establishmentId, $assessmentId);
    }

    /** @param array<string, mixed> $row */
    private function assertPublishable(string $tenantId, string $establishmentId, array $row): void
    {
        $guards = $this->guards->all();
        if ($guards === [] && !$this->settings->publishWithoutDeliberation($tenantId, $establishmentId)) {
            throw HttpException::conflict('Publication refusée : elle exige un procès-verbal de délibération signé (RG-DEL-3).');
        }
        $context = [
            'id' => (string) $row['id'], 'element_id' => (string) $row['element_id'], 'curriculum_id' => (string) $row['curriculum_id'],
            'program_id' => (string) $row['program_id'], 'level_id' => (string) $row['level_id'], 'group_id' => $row['group_id'] === null ? null : (string) $row['group_id'],
            'academic_year_id' => (string) $row['academic_year_id'], 'semester_ordinal' => (int) $row['semester_ordinal'], 'exam_session' => (string) $row['exam_session'],
        ];
        foreach ($guards as $guard) {
            $refusal = $guard->refusal($tenantId, $establishmentId, $context);
            if ($refusal !== null) {
                throw HttpException::conflict($refusal);
            }
        }
    }

    private function enteredBy(string $tenantId, string $establishmentId, string $assessmentId, string $userId): bool
    {
        return $this->store->count(MarkService::EVENTS, $tenantId, $establishmentId, ['assessment_id' => $assessmentId, 'actor_id' => $userId]) > 0;
    }

    /** @param array<string, mixed> $row */
    private function emptyCells(string $tenantId, string $establishmentId, array $row): int
    {
        $total = $this->store->count(AssessmentService::MARKS, $tenantId, $establishmentId, ['assessment_id' => $row['id']]);

        return $total - $this->assessments->enteredCount($tenantId, $establishmentId, (string) $row['id']);
    }
}
