<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\PermissionChecker;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Assessment\Contracts\ActorDirectory;
use Scholaris\Modules\Socle\Assessment\Contracts\RecentSecondFactor;
use Scholaris\Modules\Socle\Assessment\Infrastructure\CatalogRepository;
use Scholaris\Modules\Socle\Assessment\Infrastructure\Db;
use Scholaris\Modules\Socle\Assessment\Infrastructure\GradeRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Référentiel, épreuves pondérées et cycle de vie des notes (T117, T119, T328). Le cycle est déclaré par l'application
 * (AssessmentSettings::lifecycle) : états, états modifiables, droit requis par transition, 2FA récente éventuelle.
 */
final class AssessmentService
{
    /** Sessions d'examen (S7) : normale et rattrapage. */
    public const NORMAL = 'normal';
    public const RESIT = 'resit';
    public const SESSIONS = [self::NORMAL, self::RESIT];

    public function __construct(
        private readonly Db $db,
        private readonly CatalogRepository $catalog,
        private readonly GradeRepository $grades,
        private readonly AssessmentSettings $settings,
        private readonly AssessmentClock $clock,
        private readonly PermissionChecker $permissions,
        private readonly RecentSecondFactor $secondFactor,
        private readonly ActorDirectory $actors,
        private readonly ScopeGuard $scope,
        private readonly Roster $roster,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createSubject(string $tenantId, string $establishmentId, array $data): array
    {
        if ($this->catalog->subjectByCode($tenantId, $establishmentId, (string) $data['code']) !== null) {
            throw HttpException::conflict('Le code « ' . $data['code'] . ' » est déjà utilisé par une autre matière. Choisissez un autre code.');
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();

        try {
            $this->db->transaction(function () use ($tenantId, $establishmentId, $data, $id, $now): void {
                $this->catalog->insert('subjects', [
                    'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'code' => $data['code'], 'name' => $data['name'],
                    'kind' => $data['kind'] ?? 'subject', 'coefficient' => $data['coefficient'] ?? 1.0, 'credits' => $data['credits'] ?? 0,
                    'active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ((array) ($data['elements'] ?? []) as $element) {
                    $this->insertElement($tenantId, $establishmentId, $id, (array) $element, $now);
                }
            });
        } catch (PDOException $e) {
            if ($this->db->isUniqueViolation($e)) {
                throw HttpException::conflict('Ce code de matière est déjà utilisé.');
            }
            throw $e;
        }

        return $this->subject($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateSubject(string $tenantId, string $establishmentId, string $id, array $data): array
    {
        $this->subject($tenantId, $establishmentId, $id);
        $set = array_intersect_key($data, array_flip(['name', 'kind', 'coefficient', 'credits']));
        if (array_key_exists('active', $data)) {
            $set['active'] = $data['active'] === true ? 1 : 0;
        }
        if ($set !== []) {
            $this->catalog->updateSubject($tenantId, $id, $set + ['updated_at' => $this->clock->now()]);
        }

        return $this->subject($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addElement(string $tenantId, string $establishmentId, string $subjectId, array $data): array
    {
        $this->subject($tenantId, $establishmentId, $subjectId);
        $this->insertElement($tenantId, $establishmentId, $subjectId, $data, $this->clock->now());

        return $this->subject($tenantId, $establishmentId, $subjectId);
    }

    /** @return array<string, mixed> matière avec ses éléments */
    public function subject(string $tenantId, string $establishmentId, string $id): array
    {
        $subject = $this->catalog->subject($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Matière introuvable.');
        $subject['elements'] = $this->catalog->elementsOf($tenantId, $id);

        return $subject;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAssessment(Principal $principal, string $establishmentId, array $data): array
    {
        $tenantId = $principal->tenantId;
        $subject = $this->catalog->subject($tenantId, $establishmentId, (string) $data['subject_id']) ?? throw HttpException::validation(['subject_id' => ['Matière introuvable dans cet établissement.']]);
        if ((int) $subject['active'] !== 1) {
            throw HttpException::validation(['subject_id' => ['Cette matière est désactivée. Réactivez-la ou choisissez-en une autre.']]);
        }
        $elementId = $data['element_id'] ?? null;
        if ($elementId !== null) {
            $element = $this->catalog->element($tenantId, (string) $data['subject_id'], (string) $elementId);
            if ($element === null) {
                throw HttpException::validation(['element_id' => ['Cet élément n\'appartient pas à la matière choisie.']]);
            }
            if ((int) ($element['active'] ?? 1) !== 1) {
                throw HttpException::validation(['element_id' => ['Cet élément est désactivé. Réactivez-le ou choisissez-en un autre.']]);
            }
        }

        $this->assertKindAndDate($tenantId, $establishmentId, (string) $data['kind'], (string) $data['period_id'], $data['held_on'] ?? null);

        $structureId = (string) $data['structure_id'];
        $teacher = $data['teacher_person_id'] ?? null;
        if (!$this->permissions->can($principal, 'assessment.validate', $establishmentId)) {
            $teacher = $this->actors->personIdOf($tenantId, $principal->userId);
            $students = array_column($this->roster->students($tenantId, $establishmentId, $structureId), 'person_id');
            $this->scope->assertTeachesAny($principal, $students);
        }

        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->catalog->insert('assessments', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'subject_id' => $data['subject_id'], 'element_id' => $elementId,
            'structure_id' => $structureId, 'period_id' => $data['period_id'], 'academic_year_id' => $data['academic_year_id'] ?? null,
            'kind' => $data['kind'], 'title' => $data['title'], 'weight' => $data['weight'] ?? 1.0, 'max_score' => $data['max_score'] ?? 20.0,
            'grading_mode' => $data['grading_mode'] ?? 'numeric', 'anonymous' => ($data['anonymous'] ?? false) === true ? 1 : 0,
            'status' => $this->settings->lifecycle($tenantId, $establishmentId)->initial(), 'teacher_person_id' => $teacher,
            'held_on' => $data['held_on'] ?? null, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            'score_step' => $data['score_step'] ?? null, 'exam_session' => $data['exam_session'] ?? self::NORMAL,
        ]);

        return $this->assessment($tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateAssessment(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $tenantId = $principal->tenantId;
        $assessment = $this->assessment($tenantId, $establishmentId, $id);
        $this->assertEditable($tenantId, $establishmentId, $assessment, 'modifier cette épreuve');
        $this->scope->assertCanEnter($principal, $establishmentId, $assessment, []);

        $set = array_intersect_key($data, array_flip(['title', 'kind', 'weight', 'max_score', 'held_on', 'score_step']));
        if (isset($set['kind']) || isset($set['held_on'])) {
            $this->assertKindAndDate($tenantId, $establishmentId, (string) ($set['kind'] ?? $assessment['kind']), (string) $assessment['period_id'], $set['held_on'] ?? null);
        }
        if (isset($set['max_score'])) {
            foreach ($this->grades->ofAssessment($tenantId, $id) as $grade) {
                if ($grade['score'] !== null && (float) $grade['score'] > (float) $set['max_score']) {
                    throw HttpException::conflict('Une note déjà saisie dépasse le nouveau barème. Corrigez-la avant de réduire le barème.');
                }
            }
        }
        if ($set !== []) {
            $this->catalog->updateAssessment($tenantId, $id, $set + ['updated_at' => $this->clock->now()]);
        }

        return $this->assessment($tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function assessment(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->catalog->assessment($tenantId, $establishmentId, $id) ?? throw HttpException::notFound('Épreuve introuvable.');
    }

    /**
     * Fait passer une épreuve à l'état demandé si le cycle de l'application le permet et si l'appelant a le droit requis.
     *
     * @return array<string, mixed>
     */
    public function transition(Principal $principal, string $establishmentId, string $id, string $to): array
    {
        $tenantId = $principal->tenantId;
        $assessment = $this->assessment($tenantId, $establishmentId, $id);
        $this->applyTransition($principal, $establishmentId, $assessment, $to);

        return $this->assessment($tenantId, $establishmentId, $id);
    }

    /**
     * Transition de toutes les épreuves d'une classe pour une période (« Valider la grille »).
     *
     * @return list<array<string, mixed>>
     */
    public function transitionSheet(Principal $principal, string $establishmentId, string $structureId, string $periodId, ?string $subjectId, string $to, ?string $session = null): array
    {
        $tenantId = $principal->tenantId;
        $assessments = $this->catalog->forSheet($tenantId, $establishmentId, $structureId, $periodId, $subjectId, $session);
        if ($assessments === []) {
            throw HttpException::notFound('Aucune épreuve pour cette classe et cette période.');
        }

        return $this->db->transaction(function () use ($principal, $establishmentId, $assessments, $to, $tenantId): array {
            $out = [];
            foreach ($assessments as $assessment) {
                if ($assessment['status'] === $to) {
                    $out[] = $assessment;
                    continue;
                }
                $this->applyTransition($principal, $establishmentId, $assessment, $to);
                $out[] = $this->assessment($tenantId, $establishmentId, (string) $assessment['id']);
            }

            return $out;
        });
    }

    /**
     * Lève l'anonymat des copies : irréversible, réservé à celui qui valide.
     *
     * @return array<string, mixed>
     */
    public function reveal(Principal $principal, string $establishmentId, string $id): array
    {
        $assessment = $this->assessment($principal->tenantId, $establishmentId, $id);
        if ((int) $assessment['anonymous'] !== 1) {
            throw HttpException::conflict('Cette épreuve n\'est pas anonyme.');
        }
        $this->catalog->updateAssessment($principal->tenantId, $id, ['anonymous' => 0, 'updated_at' => $this->clock->now()]);
        $this->audit->append($principal->tenantId, $principal->userId, 'assessment.anonymity.lifted', 'assessment', $id, ['anonymous' => true], ['anonymous' => false]);

        return $this->assessment($principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $assessment
     * @throws HttpException 409 si la saisie est verrouillée
     */
    public function assertEditable(string $tenantId, string $establishmentId, array $assessment, string $action): void
    {
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId);
        if (!$lifecycle->isEditable((string) $assessment['status'])) {
            throw HttpException::conflict(sprintf('Impossible de %s : l\'épreuve est à l\'état « %s » et verrouillée. Déposez une demande de correction motivée.', $action, $assessment['status']));
        }
    }

    /** @param array<string, mixed> $assessment */
    private function applyTransition(Principal $principal, string $establishmentId, array $assessment, string $to): void
    {
        $tenantId = $principal->tenantId;
        $lifecycle = $this->settings->lifecycle($tenantId, $establishmentId);
        $from = (string) $assessment['status'];
        if (!in_array($to, $lifecycle->states, true)) {
            throw HttpException::validation(['to' => ['État inconnu. États possibles : ' . implode(', ', $lifecycle->states) . '.']]);
        }
        $transition = $lifecycle->transition($from, $to);
        if ($transition === null) {
            $targets = $lifecycle->targetsFrom($from);
            throw HttpException::conflict(sprintf(
                'Passage impossible de « %s » à « %s ». %s',
                $from,
                $to,
                $targets === [] ? 'Aucune étape n\'est possible depuis cet état.' : 'Étapes possibles : ' . implode(', ', $targets) . '.',
            ));
        }
        if (!$this->permissions->can($principal, $transition['permission'], $establishmentId)) {
            throw HttpException::forbidden(sprintf('Vous n\'avez pas le droit de faire passer cette épreuve de « %s » à « %s ».', $from, $to));
        }
        if ($transition['require_recent_2fa'] && !$this->secondFactor->isRecent($tenantId, $principal->userId)) {
            throw HttpException::forbidden('Cette étape exige une double authentification récente. Confirmez votre second facteur puis recommencez.');
        }
        $this->assertSegregated($principal, $establishmentId, $assessment, $lifecycle->isEditable($from) && !$lifecycle->isEditable($to), $to);
        if ($lifecycle->isEditable($from) && !$lifecycle->isEditable($to) && ($assessment['exam_session'] ?? self::NORMAL) === self::NORMAL) {
            $this->assertComplete($tenantId, $establishmentId, $assessment);
        }

        $this->catalog->updateAssessment($tenantId, (string) $assessment['id'], ['status' => $to, 'updated_at' => $this->clock->now()]);
        $this->audit->append($tenantId, $principal->userId, 'assessment.status.changed', 'assessment', (string) $assessment['id'], ['status' => $from], ['status' => $to]);
    }

    /**
     * S4 (option assessment.segregate_lock) : qui a saisi une note de l'épreuve ne peut pas la faire sortir de la saisie
     * (ou la faire passer aux états cibles déclarés).
     *
     * @param array<string, mixed> $assessment
     */
    private function assertSegregated(Principal $principal, string $establishmentId, array $assessment, bool $leavesEntry, string $to): void
    {
        $targets = $this->settings->segregatedTargets($principal->tenantId, $establishmentId);
        if ($targets === null || ($targets === [] && !$leavesEntry) || ($targets !== [] && !in_array($to, $targets, true))) {
            return;
        }
        if (in_array($principal->userId, $this->grades->authorsOf($principal->tenantId, (string) $assessment['id']), true)) {
            throw HttpException::forbidden(sprintf('Vous avez saisi des notes de « %s » : une autre personne doit la faire passer à l\'état « %s ».', $assessment['title'], $to));
        }
    }

    /**
     * Saisie complète avant de quitter la saisie. Une épreuve de rattrapage n'est passée que par les convoqués :
     * une cellule vide y signifie « pas de rattrapage », elle n'est pas exigée.
     *
     * @param array<string, mixed> $assessment
     */
    private function assertComplete(string $tenantId, string $establishmentId, array $assessment): void
    {
        $students = $this->roster->students($tenantId, $establishmentId, (string) $assessment['structure_id']);
        $entered = [];
        foreach ($this->grades->ofAssessment($tenantId, (string) $assessment['id']) as $grade) {
            if ($grade['score'] !== null || $grade['absence'] !== null || $grade['qualitative'] !== null) {
                $entered[(string) $grade['person_id']] = true;
            }
        }
        $missing = 0;
        foreach ($students as $student) {
            if (!isset($entered[$student['person_id']])) {
                ++$missing;
            }
        }
        if ($missing > 0) {
            throw HttpException::validation(
                ['grades' => [sprintf('%s : %d %s. Corrigez avant de valider.', $assessment['title'], $missing, $missing === 1 ? 'note manquante' : 'notes manquantes')]],
                'La saisie est incomplète.',
            );
        }
    }

    /** @param array<string, mixed> $element */
    private function insertElement(string $tenantId, string $establishmentId, string $subjectId, array $element, string $now): void
    {
        $this->catalog->insert('subject_elements', [
            'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'subject_id' => $subjectId,
            'name' => $element['name'], 'coefficient' => $element['coefficient'] ?? 1.0, 'credits' => $element['credits'] ?? 0, 'created_at' => $now,
        ]);
    }

    /** Type d'épreuve dans la liste de l'établissement (assessment.kinds) et date de l'épreuve dans les bornes de sa période. */
    private function assertKindAndDate(string $tenantId, string $establishmentId, string $kind, string $periodId, mixed $heldOn): void
    {
        $kinds = $this->settings->kinds($tenantId, $establishmentId);
        if (!in_array($kind, $kinds, true)) {
            throw HttpException::validation(['kind' => ['Type d\'épreuve inconnu. Valeurs acceptées : ' . implode(', ', $kinds) . '.']]);
        }
        if (!is_string($heldOn) || $heldOn === '') {
            return;
        }
        $period = $this->db->one('SELECT starts_on, ends_on FROM periods WHERE tenant_id = ? AND establishment_id = ? AND id = ?', [$tenantId, $establishmentId, $periodId]);
        if ($period === null) {
            return;
        }
        $from = substr((string) $period['starts_on'], 0, 10);
        $to = substr((string) $period['ends_on'], 0, 10);
        $day = substr($heldOn, 0, 10);
        if ($day < $from || $day > $to) {
            throw HttpException::validation(['held_on' => ['La date de l\'épreuve doit être comprise dans sa période (du ' . $from . ' au ' . $to . ').']]);
        }
    }
}
