<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Exams\Infrastructure\PdoExams;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Reach;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\Reporting\Contracts\ApprovalWorkflow;

/**
 * Sessions et listes de candidats (EX-EXA-01 à 04, T680 à T682, API-09) :
 *  - session nationale (CEP, FSLC, entrée en 6e, Common Entrance) ouverte par la direction des examens (R02), classe d'examen et
 *    sous-système lus du catalogue paramétré ;
 *  - liste de l'école générée à partir des inscrits des classes d'examen, tenue par le directeur ;
 *  - circuit (EX-EXA-03, contrat ApprovalWorkflow) : directeur > inspection (contrôle) > délégation départementale (consolidation, gel) ;
 *    l'inspection valide chaque candidat dont l'acte de naissance est vérifié et BLOQUE les autres, eux seuls (RG-EXA-1, TST-07) ;
 *  - liste figée à la validation de la délégation (instantané) ; tout ajout ou retrait ultérieur est motivé et tracé (RG-EXA-2).
 */
final class ExamListService
{
    public const STEPS = ['inspection', 'delegation'];
    public const BLOCKED_BIRTH_ACT = 'birth_act_unverified';

    public function __construct(
        private readonly PdoExams $repository,
        private readonly ExamAccess $access,
        private readonly ExamSupport $support,
        private readonly ClassRoster $classes,
        private readonly SchoolRegistry $schools,
        private readonly ApprovalWorkflow $workflow,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function sessions(Principal $principal, string $establishmentId, ?string $yearLabel): array
    {
        $this->access->reader($principal, $establishmentId);

        return array_map(static fn (array $row): array => ExamSupport::sessionResource($row), $this->repository->sessions($principal->tenantId, $yearLabel));
    }

    /**
     * Ouvre une session nationale (R02) : `exam_code` du catalogue, `year_label`, `written_on` facultatif.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSession(Principal $principal, string $establishmentId, array $input): array
    {
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::CONSOLIDATE);
        if (!$reach->wholeSchool || !$this->access->isNational($reach)) {
            throw HttpException::forbidden('Les sessions d\'examen sont ouvertes au niveau national (direction des examens).');
        }
        $tenantId = $principal->tenantId;
        $catalogue = $this->support->catalogue($tenantId, $establishmentId);
        $errors = [];
        $code = $input['exam_code'] ?? null;
        if (!is_string($code) || !isset($catalogue[$code])) {
            $errors['exam_code'][] = 'Examen attendu : ' . implode(', ', array_keys($catalogue)) . '.';
        }
        $year = $input['year_label'] ?? null;
        if (!Input::isYearLabel($year)) {
            $errors['year_label'][] = 'Année scolaire AAAA-AAAA attendue.';
        }
        $writtenOn = $input['written_on'] ?? null;
        if ($writtenOn !== null && !Input::isDate($writtenOn)) {
            $errors['written_on'][] = 'Date AAAA-MM-JJ attendue.';
        }
        $label = Input::text($input['label'] ?? null, 160, 'label', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($this->repository->sessionByCode($tenantId, (string) $code, (string) $year) !== null) {
            throw HttpException::conflict('Cette session existe déjà.');
        }
        $entry = $catalogue[(string) $code];
        $levels = array_values(array_filter((array) ($entry['levels'] ?? []), 'is_string'));
        if ($levels === []) {
            throw new HttpException(500, 'Paramètres invalides', 'public_primary.exams.catalogue : classe d\'examen manquante pour ' . $code . '.');
        }
        $now = $this->clock->now();
        $id = $this->repository->insert('pp_exam_sessions', [
            'tenant_id' => $tenantId, 'exam_code' => (string) $code, 'year_label' => (string) $year,
            'label' => $label ?? (string) ($entry['label_fr'] ?? $code), 'subsystem' => (string) ($entry['subsystem'] ?? 'francophone'),
            'level_codes' => implode(',', $levels), 'exam_kind' => (string) ($entry['kind'] ?? 'certificate'), 'written_on' => is_string($writtenOn) ? $writtenOn : null,
            'session_state' => 'open', 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.session_opened', 'pp_exam_session', $id, null, ['exam_code' => $code, 'year_label' => $year]);

        return ExamSupport::sessionResource($this->support->session($tenantId, $id));
    }

    /**
     * Session et liste de l'école consultée (avec ses compteurs).
     *
     * @return array<string, mixed>
     */
    public function show(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->access->reader($principal, $establishmentId);
        $session = $this->support->session($principal->tenantId, $sessionId);
        $list = $this->repository->listOf($principal->tenantId, $sessionId, $establishmentId);
        $counts = [];
        foreach ($list === null ? [] : $this->repository->candidatesOfList($principal->tenantId, (string) $list['id']) as $row) {
            $counts[(string) $row['candidate_state']] = ($counts[(string) $row['candidate_state']] ?? 0) + 1;
        }

        return ExamSupport::sessionResource($session) + ['list' => ExamSupport::listResource($list), 'counts' => $counts];
    }

    /**
     * Candidats de la liste de l'école : le directeur et la supervision voient toute la liste (supervision : lecture journalisée,
     * finalité `exam`) ; un enseignant ne voit que sa classe.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $reach = $this->access->reader($principal, $establishmentId);
        $this->support->session($principal->tenantId, $sessionId);
        $list = $this->repository->listOf($principal->tenantId, $sessionId, $establishmentId);
        if ($list === null) {
            return [];
        }
        $this->access->journal($principal, $reach, $establishmentId, 'exam_list', (string) $list['id']);
        $rows = $this->repository->candidatesOfList($principal->tenantId, (string) $list['id']);
        if (!$reach->wholeSchool && !$reach->isTerritorial()) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['structure_id'] !== null && $reach->coversClass((string) $row['structure_id'])));
        }
        $supervision = $reach->isTerritorial() || $this->access->isNational($reach);

        return array_map(fn (array $row): array => $this->support->candidate($principal->tenantId, $row, $supervision), $rows);
    }

    /**
     * Génère (ou complète) la liste à partir des élèves inscrits dans les classes d'examen (niveau paramétré de la session). Un élève
     * déjà candidat n'est pas dupliqué ; un candidat retiré n'est pas réinscrit.
     *
     * @param array<string, mixed> $input academic_year_id
     * @return list<array<string, mixed>>
     */
    public function generate(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_list', $sessionId);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $yearId = $input['academic_year_id'] ?? null;
        if (!is_string($yearId) || trim($yearId) === '') {
            throw HttpException::validation(['academic_year_id' => ['Année scolaire (identifiant) requise.']]);
        }
        $this->assertSubsystem($tenantId, $establishmentId, $session);
        $list = $this->openList($principal, $establishmentId, $session);
        if ($list['frozen_at'] !== null) {
            throw HttpException::conflict('Liste figée par la délégation : tout ajout passe par un ajout motivé (RG-EXA-2).');
        }
        if (!in_array($list['list_state'], ['draft', 'returned'], true)) {
            throw HttpException::conflict('Liste transmise : elle ne peut plus être générée.');
        }
        $levels = ExamSupport::levels($session);
        $today = $this->clock->today();
        $now = $this->clock->now();
        foreach ($this->classes->classesOf($tenantId, $establishmentId, $yearId) as $class) {
            $level = array_values(array_intersect($class->levelCodes, $levels))[0] ?? null;
            if ($level === null) {
                continue;
            }
            foreach ($this->classes->pupilIdsIn($tenantId, $establishmentId, $class->structureId, $today) as $personId) {
                if ($this->repository->candidateOfPerson($tenantId, $sessionId, $personId) !== null) {
                    continue;
                }
                $this->insertCandidate($tenantId, $establishmentId, $sessionId, (string) $list['id'], $personId, $class->structureId, $level, false, null, $now);
            }
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.list_generated', 'pp_exam_list', (string) $list['id'], null, ['session_id' => $sessionId]);

        return $this->candidates($principal, $establishmentId, $sessionId);
    }

    /**
     * Ajoute un candidat (élève de l'école). Après le gel (RG-EXA-2) : motif obligatoire, ajout tracé et compté, à valider par la délégation.
     *
     * @param array<string, mixed> $input person_id, structure_id?, reason?
     * @return array<string, mixed>
     */
    public function add(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_list', $sessionId);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $errors = [];
        $personId = Input::text($input['person_id'] ?? null, 40, 'person_id', $errors, true);
        $structureId = Input::text($input['structure_id'] ?? null, 40, 'structure_id', $errors);
        $reason = Input::text($input['reason'] ?? null, 500, 'reason', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($this->support->pupil($tenantId, (string) $personId) === null) {
            throw HttpException::validation(['person_id' => ['Élève inconnu du registre des écoles publiques.']]);
        }
        $level = null;
        if ($structureId !== null) {
            $class = $this->classes->find($tenantId, $establishmentId, $structureId) ?? throw HttpException::validation(['structure_id' => ['Classe inconnue dans cette école.']]);
            if (!in_array((string) $personId, $this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $this->clock->today()), true)) {
                throw HttpException::validation(['person_id' => ['Élève non inscrit dans cette classe.']]);
            }
            $level = array_values(array_intersect($class->levelCodes, ExamSupport::levels($session)))[0] ?? ($class->levelCodes[0] ?? null);
        }
        $this->assertSubsystem($tenantId, $establishmentId, $session);
        $list = $this->openList($principal, $establishmentId, $session);
        $frozen = $list['frozen_at'] !== null;
        if (!$frozen && !in_array($list['list_state'], ['draft', 'returned'], true)) {
            throw HttpException::conflict('Liste en cours de contrôle : attendez son renvoi ou sa validation.');
        }
        if ($frozen && $reason === null) {
            throw HttpException::validation(['reason' => ['Liste figée : tout ajout est motivé (RG-EXA-2).']]);
        }
        if ($this->repository->candidateOfPerson($tenantId, $sessionId, (string) $personId) !== null) {
            throw HttpException::conflict('Cet élève est déjà candidat à cette session.');
        }
        $id = $this->insertCandidate($tenantId, $establishmentId, $sessionId, (string) $list['id'], (string) $personId, $structureId, $level, $frozen, $reason, $this->clock->now());
        if ($frozen) {
            $this->repository->update('pp_exam_lists', $tenantId, (string) $list['id'], ['amendments_count' => (int) $list['amendments_count'] + 1, 'updated_at' => $this->clock->now()]);
        }
        $this->audit->append($tenantId, $principal->userId, $frozen ? 'public_primary.exams.candidate_added_after_freeze' : 'public_primary.exams.candidate_added', 'pp_exam_candidate', $id, null, ['reason' => $reason]);

        return $this->support->candidate($tenantId, $this->repository->candidate($tenantId, $establishmentId, $id) ?? throw HttpException::notFound());
    }

    /**
     * @param array<string, mixed> $input reason
     * @return array<string, mixed>
     */
    public function withdraw(Principal $principal, string $establishmentId, string $candidateId, array $input): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_candidate', $candidateId);
        $reason = ExamSupport::reason($input);
        $tenantId = $principal->tenantId;
        $row = $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? throw HttpException::notFound();
        if ($row['candidate_state'] === 'withdrawn' || $row['outcome'] !== null) {
            throw HttpException::conflict('Candidat déjà retiré ou résultat déjà importé.');
        }
        $list = $this->repository->list($tenantId, (string) $row['list_id']) ?? throw HttpException::notFound();
        $this->repository->update('pp_exam_candidates', $tenantId, $candidateId, ['candidate_state' => 'withdrawn', 'withdrawn_reason' => $reason, 'updated_at' => $this->clock->now()]);
        if ($list['frozen_at'] !== null) {
            $this->repository->update('pp_exam_lists', $tenantId, (string) $list['id'], ['amendments_count' => (int) $list['amendments_count'] + 1, 'updated_at' => $this->clock->now()]);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.candidate_withdrawn', 'pp_exam_candidate', $candidateId, null, ['reason' => $reason, 'after_freeze' => $list['frozen_at'] !== null]);

        return $this->support->candidate($tenantId, $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? $row);
    }

    /**
     * Le directeur transmet la liste à l'inspection.
     *
     * @return array<string, mixed>
     */
    public function submit(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $this->access->director($principal, $establishmentId, 'pp_exam_list', $sessionId);
        $tenantId = $principal->tenantId;
        $list = $this->repository->listOf($tenantId, $sessionId, $establishmentId) ?? throw HttpException::conflict('Aucune liste : générez-la d\'abord.');
        if (!in_array($list['list_state'], ['draft', 'returned'], true)) {
            throw HttpException::conflict('Liste déjà transmise.');
        }
        $active = array_filter($this->repository->candidatesOfList($tenantId, (string) $list['id']), static fn (array $row): bool => $row['candidate_state'] !== 'withdrawn');
        if ($active === []) {
            throw HttpException::conflict('Liste vide : aucun candidat à transmettre.');
        }
        $this->workflow->submit($tenantId, (string) $list['id'], $principal->userId, self::STEPS);
        $this->repository->update('pp_exam_lists', $tenantId, (string) $list['id'], ['list_state' => 'submitted', 'current_step' => self::STEPS[0], 'return_reason' => null, 'updated_at' => $this->clock->now()]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.list_submitted', 'pp_exam_list', (string) $list['id'], null, ['candidates' => count($active)]);

        return ExamSupport::listResource($this->repository->list($tenantId, (string) $list['id'])) ?? [];
    }

    /**
     * API-09 : validation de la liste par l'étape en cours. Inspection : chaque candidat proposé dont l'acte de naissance est vérifié
     * est validé, les autres sont bloqués, eux seuls (RG-EXA-1, TST-07). Délégation : validation et gel (instantané des candidats validés).
     *
     * @return array<string, mixed>
     */
    public function validate(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->validator($principal, $establishmentId, $sessionId);
        $list = $this->repository->listOf($tenantId, $sessionId, $establishmentId) ?? throw HttpException::notFound('Aucune liste pour cette école.');
        if (!in_array($list['list_state'], ['submitted', 'inspected'], true)) {
            throw HttpException::conflict('Aucune validation attendue pour cette liste.');
        }
        $step = (string) $list['current_step'];
        $this->assertStep($principal, $reach, $step, (string) $list['id']);
        $now = $this->clock->now();
        $blocked = [];
        $validated = 0;
        if ($step === self::STEPS[0]) {
            foreach ($this->repository->candidatesOfList($tenantId, (string) $list['id']) as $row) {
                if (!in_array($row['candidate_state'], ['proposed', 'blocked'], true)) {
                    continue;
                }
                $pupil = $this->support->pupil($tenantId, (string) $row['person_id']);
                if ($pupil === null || !$pupil->birthActVerified) {
                    $this->repository->update('pp_exam_candidates', $tenantId, (string) $row['id'], ['candidate_state' => 'blocked', 'blocked_reason' => self::BLOCKED_BIRTH_ACT, 'updated_at' => $now]);
                    $blocked[] = (string) $row['id'];
                    continue;
                }
                $this->repository->update('pp_exam_candidates', $tenantId, (string) $row['id'], ['candidate_state' => 'validated', 'blocked_reason' => null, 'updated_at' => $now]);
                ++$validated;
            }
        }
        $next = $this->workflow->approve($tenantId, (string) $list['id'], $step, $principal->userId);
        if ($next === null) {
            $snapshot = array_map(static fn (array $row): array => ['candidate_id' => (string) $row['id'], 'person_id' => (string) $row['person_id']], array_values(array_filter(
                $this->repository->candidatesOfList($tenantId, (string) $list['id']),
                static fn (array $row): bool => $row['candidate_state'] === 'validated',
            )));
            $this->workflow->freezeSnapshot($tenantId, (string) $list['id'], 'v1', ['session_id' => $sessionId, 'candidates' => $snapshot]);
            $this->repository->update('pp_exam_lists', $tenantId, (string) $list['id'], ['list_state' => 'validated', 'current_step' => null, 'frozen_at' => $now, 'frozen_by' => $principal->userId, 'updated_at' => $now]);
        } else {
            $this->repository->update('pp_exam_lists', $tenantId, (string) $list['id'], ['list_state' => 'inspected', 'current_step' => $next, 'updated_at' => $now]);
        }
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.list_validated', 'pp_exam_list', (string) $list['id'], null, ['step' => $step, 'validated' => $validated, 'blocked' => $blocked]);

        return ['list' => ExamSupport::listResource($this->repository->list($tenantId, (string) $list['id'])), 'step' => $step, 'validated' => $validated, 'blocked' => $blocked];
    }

    /**
     * Renvoi motivé de la liste au directeur par l'étape en cours.
     *
     * @param array<string, mixed> $input reason
     * @return array<string, mixed>
     */
    public function sendBack(Principal $principal, string $establishmentId, string $sessionId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->validator($principal, $establishmentId, $sessionId);
        $reason = ExamSupport::reason($input);
        $list = $this->repository->listOf($tenantId, $sessionId, $establishmentId) ?? throw HttpException::notFound('Aucune liste pour cette école.');
        if (!in_array($list['list_state'], ['submitted', 'inspected'], true)) {
            throw HttpException::conflict('Seule une liste en cours de contrôle peut être renvoyée.');
        }
        $step = (string) $list['current_step'];
        $this->assertStep($principal, $reach, $step, (string) $list['id']);
        $this->workflow->returnForRevision($tenantId, (string) $list['id'], $step, $principal->userId, $reason);
        $this->repository->update('pp_exam_lists', $tenantId, (string) $list['id'], ['list_state' => 'returned', 'current_step' => null, 'return_reason' => $reason, 'updated_at' => $this->clock->now()]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.list_returned', 'pp_exam_list', (string) $list['id'], null, ['step' => $step, 'reason' => $reason]);

        return ExamSupport::listResource($this->repository->list($tenantId, (string) $list['id'])) ?? [];
    }

    /**
     * Valide un candidat bloqué dont l'acte est désormais vérifié, ou un ajout postérieur au gel (délégation seulement, RG-EXA-2).
     *
     * @return array<string, mixed>
     */
    public function validateCandidate(Principal $principal, string $establishmentId, string $candidateId): array
    {
        $tenantId = $principal->tenantId;
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::VALIDATE);
        $row = $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? throw HttpException::notFound();
        $list = $this->repository->list($tenantId, (string) $row['list_id']) ?? throw HttpException::notFound();
        $types = $this->access->territoryTypes($reach);
        $needed = $list['frozen_at'] !== null ? ExamAccess::DELEGATION_TYPE : null;
        if (!$reach->isTerritorial() || ($needed !== null && !in_array($needed, $types, true))
            || ($needed === null && array_intersect($types, [ExamAccess::INSPECTION_TYPE, ExamAccess::DELEGATION_TYPE]) === [])) {
            throw HttpException::forbidden($needed !== null ? 'Liste figée : seule la délégation valide un ajout (RG-EXA-2).' : 'Validation réservée à l\'inspection et à la délégation.');
        }
        if (!in_array($row['candidate_state'], ['blocked', 'proposed'], true)) {
            throw HttpException::conflict('Aucune validation attendue pour ce candidat.');
        }
        if ($list['frozen_at'] === null && $list['list_state'] !== 'inspected' && $row['candidate_state'] === 'proposed') {
            throw HttpException::conflict('Le candidat est validé avec la liste (API-09).');
        }
        $pupil = $this->support->pupil($tenantId, (string) $row['person_id']);
        if ($pupil === null || !$pupil->birthActVerified) {
            $this->repository->update('pp_exam_candidates', $tenantId, $candidateId, ['candidate_state' => 'blocked', 'blocked_reason' => self::BLOCKED_BIRTH_ACT, 'updated_at' => $this->clock->now()]);
            throw HttpException::conflict('Acte de naissance non vérifié : le candidat ne peut pas être validé (RG-EXA-1).');
        }
        $this->repository->update('pp_exam_candidates', $tenantId, $candidateId, ['candidate_state' => 'validated', 'blocked_reason' => null, 'updated_at' => $this->clock->now()]);
        $this->audit->append($tenantId, $principal->userId, 'public_primary.exams.candidate_validated', 'pp_exam_candidate', $candidateId, null, ['after_freeze' => $list['frozen_at'] !== null]);

        return $this->support->candidate($tenantId, $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? $row, true);
    }

    /**
     * Listes des écoles d'un territoire (délégation, R02) : état, compteurs, ajouts après gel ; aucune donnée nominative.
     *
     * @return list<array<string, mixed>>
     */
    public function territoryLists(Principal $principal, string $establishmentId, string $sessionId, string $territoryId): array
    {
        $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::VALIDATE, ExamAccess::CONSOLIDATE, ExamAccess::IMPORT);
        $tenantId = $principal->tenantId;
        $this->support->session($tenantId, $sessionId);
        $lists = [];
        foreach ($this->repository->listsIn($tenantId, $sessionId, $scope['establishments']) as $list) {
            $lists[(string) $list['establishment_id']] = $list;
        }
        $counts = [];
        foreach ($this->repository->candidatesIn($tenantId, $sessionId, $scope['establishments']) as $row) {
            $counts[(string) $row['establishment_id']][(string) $row['candidate_state']] = ($counts[(string) $row['establishment_id']][(string) $row['candidate_state']] ?? 0) + 1;
        }
        $rows = [];
        foreach ($scope['establishments'] as $schoolId) {
            $rows[] = [
                'id' => $schoolId, 'establishment_id' => $schoolId, 'official_code' => $this->support->schoolCode($tenantId, $schoolId),
                'list' => ExamSupport::listResource($lists[$schoolId] ?? null), 'counts' => $counts[$schoolId] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Lignes d'export de la liste (Q10 : format officiel à obtenir ; gabarit par défaut : identifiant national, nom, prénoms, sexe,
     * date de naissance, référence d'acte, école, classe). École (`$territoryId` null) ou consolidation d'un territoire (listes figées).
     *
     * @return list<array<string, scalar|null>>
     */
    public function exportRows(Principal $principal, string $establishmentId, string $sessionId, ?string $territoryId): array
    {
        $tenantId = $principal->tenantId;
        if ($territoryId === null) {
            $reach = $this->access->reader($principal, $establishmentId);
            $schools = [$establishmentId];
        } else {
            $scope = $this->access->territory($principal, $establishmentId, $territoryId, ExamAccess::VALIDATE, ExamAccess::CONSOLIDATE, ExamAccess::IMPORT);
            $reach = $scope['reach'];
            $schools = $scope['establishments'];
        }
        $session = $this->support->session($tenantId, $sessionId);
        $rows = [];
        foreach ($schools as $schoolId) {
            $list = $this->repository->listOf($tenantId, $sessionId, $schoolId);
            if ($list === null || ($territoryId !== null && $list['frozen_at'] === null)) {
                continue;
            }
            $this->access->journal($principal, $reach, $schoolId, 'exam_list_export', (string) $list['id']);
            $code = $this->support->schoolCode($tenantId, $schoolId);
            foreach ($this->repository->candidatesOfList($tenantId, (string) $list['id']) as $row) {
                if ($row['candidate_state'] === 'withdrawn' || ($territoryId !== null && $row['candidate_state'] !== 'validated')) {
                    continue;
                }
                $candidate = $this->support->candidate($tenantId, $row, true);
                $rows[] = [
                    'exam' => (string) $session['exam_code'], 'year_label' => (string) $session['year_label'], 'learner_number' => $candidate['learner_number'],
                    'last_name' => $candidate['last_name'], 'first_name' => $candidate['first_name'], 'sex' => $candidate['sex'], 'birth_date' => $candidate['birth_date'],
                    'birth_place' => null, 'birth_act_ref' => $candidate['birth_act_ref'], 'school_code' => $code,
                    'class_name' => $this->support->className($tenantId, $schoolId, $row['structure_id'] !== null ? (string) $row['structure_id'] : null),
                    'candidate_number' => $candidate['candidate_number'], 'state' => $candidate['state'],
                ];
            }
        }

        return $rows;
    }

    /**
     * DOC-13 (CEP, FSLC) ou DOC-15 (entrée en 6e, Common Entrance) : liste imprimable de l'école.
     *
     * @return array<string, mixed>
     */
    public function document(Principal $principal, string $establishmentId, string $sessionId, string $locale): array
    {
        $this->access->reader($principal, $establishmentId, ExamAccess::WRITE, ExamAccess::VALIDATE);
        $tenantId = $principal->tenantId;
        $session = $this->support->session($tenantId, $sessionId);
        $list = $this->repository->listOf($tenantId, $sessionId, $establishmentId) ?? throw HttpException::conflict('Aucune liste pour cette école.');
        $lines = [];
        $count = 0;
        foreach ($this->repository->candidatesOfList($tenantId, (string) $list['id']) as $row) {
            if ($row['candidate_state'] === 'withdrawn') {
                continue;
            }
            $candidate = $this->support->candidate($tenantId, $row);
            ++$count;
            $lines[] = sprintf(
                '%3d  %-16s %-34s %-1s %-10s %-14s %s',
                $count,
                (string) ($candidate['learner_number'] ?? '-'),
                mb_strtoupper((string) $candidate['last_name']) . ' ' . $candidate['first_name'],
                (string) ($candidate['sex'] ?? '-'),
                (string) ($candidate['birth_date'] ?? '-'),
                (string) ($candidate['birth_act_ref'] ?? '-'),
                $candidate['state'] . ($candidate['added_after_freeze'] ? ' *' : ''),
            );
        }
        $en = $locale === 'en';
        $type = $session['exam_kind'] === 'competition' ? 'pp_doc15_entrance_list' : 'pp_doc13_exam_list';
        $document = $this->support->issue($principal, $establishmentId, $type, null, [
            'exam_label' => $this->support->examLabel($session), 'school_year' => (string) $session['year_label'],
            'list_state_label' => (string) $list['list_state'] . ($list['frozen_at'] !== null ? ($en ? ' (frozen)' : ' (figée)') : ''),
            'candidates_block' => implode("\n", $lines),
            'totals_line' => ($en ? 'Candidates: ' : 'Candidats : ') . $count . ' · ' . ($en ? 'additions after freeze: ' : 'ajouts après gel : ') . (int) $list['amendments_count'],
        ], $locale);

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    /**
     * DOC-14 : fiche individuelle du candidat (identifiant national, emplacement de la photo collée, état civil, centre).
     *
     * @return array<string, mixed>
     */
    public function sheet(Principal $principal, string $establishmentId, string $candidateId, string $locale): array
    {
        $this->access->reader($principal, $establishmentId, ExamAccess::WRITE);
        $tenantId = $principal->tenantId;
        $row = $this->repository->candidate($tenantId, $establishmentId, $candidateId) ?? throw HttpException::notFound();
        $session = $this->support->session($tenantId, (string) $row['session_id']);
        $candidate = $this->support->candidate($tenantId, $row);
        $en = $locale === 'en';
        $centre = $row['centre_id'] !== null ? $this->repository->centre($tenantId, (string) $row['session_id'], (string) $row['centre_id']) : null;
        $document = $this->support->issue($principal, $establishmentId, 'pp_doc14_candidate_sheet', (string) $row['person_id'], [
            'exam_label' => $this->support->examLabel($session), 'school_year' => (string) $session['year_label'],
            'full_name' => mb_strtoupper((string) $candidate['last_name']) . ' ' . $candidate['first_name'],
            'learner_number' => (string) ($candidate['learner_number'] ?? '-'), 'sex_label' => (string) ($candidate['sex'] ?? '-'),
            'birth_line' => (string) ($candidate['birth_date'] ?? '-'),
            'birth_act_line' => ($candidate['birth_act_ref'] ?? '-') . ' · ' . ($candidate['birth_act_verified'] ? ($en ? 'verified' : 'vérifié') : ($en ? 'NOT verified' : 'NON vérifié')),
            'class_name' => $this->support->className($tenantId, $establishmentId, $row['structure_id'] !== null ? (string) $row['structure_id'] : null),
            'candidate_number' => (string) ($row['candidate_number'] ?? '-'), 'centre_label' => $centre === null ? '-' : (string) $centre['code'] . ' ' . (string) $centre['label'],
        ], $locale);

        return ['document' => ['id' => $document->id, 'number' => $document->number]];
    }

    private function validator(Principal $principal, string $establishmentId, string $sessionId): Reach
    {
        $reach = $this->access->reader($principal, $establishmentId, ExamAccess::VALIDATE);
        $this->support->session($principal->tenantId, $sessionId);
        if (!$reach->isTerritorial()) {
            throw HttpException::forbidden('La validation des listes relève de l\'inspection et de la délégation.');
        }

        return $reach;
    }

    /** Étape « inspection » : affectation d'arrondissement ; « délégation » : affectation de département. 403 journalisé sinon. */
    private function assertStep(Principal $principal, Reach $reach, string $step, string $listId): void
    {
        $needed = $step === self::STEPS[0] ? ExamAccess::INSPECTION_TYPE : ExamAccess::DELEGATION_TYPE;
        if (in_array($needed, $this->access->territoryTypes($reach), true)) {
            return;
        }
        $this->audit->append($principal->tenantId, $principal->userId, 'public_primary.scope.denied', 'pp_exam_list', $listId, null, ['step' => $step]);

        throw HttpException::forbidden($step === self::STEPS[0] ? 'Étape de l\'inspection d\'arrondissement.' : 'Étape de la délégation départementale.');
    }

    /** @param array<string, mixed> $session */
    private function assertSubsystem(string $tenantId, string $establishmentId, array $session): void
    {
        $school = $this->schools->find($tenantId, $establishmentId) ?? throw HttpException::validation(['school' => ['Fiche de l\'école requise.']]);
        if ($school->subsystem !== 'bilingual' && $school->subsystem !== (string) $session['subsystem']) {
            throw HttpException::validation(['session' => ['Examen d\'un autre sous-système (' . $session['subsystem'] . ').']]);
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function openList(Principal $principal, string $establishmentId, array $session): array
    {
        if ($session['session_state'] !== 'open') {
            throw HttpException::conflict('Session close.');
        }
        $tenantId = $principal->tenantId;
        $list = $this->repository->listOf($tenantId, (string) $session['id'], $establishmentId);
        if ($list !== null) {
            return $list;
        }
        $now = $this->clock->now();
        $id = $this->repository->insert('pp_exam_lists', [
            'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'session_id' => (string) $session['id'], 'list_state' => 'draft',
            'amendments_count' => 0, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->repository->list($tenantId, $id) ?? throw HttpException::notFound();
    }

    private function insertCandidate(string $tenantId, string $establishmentId, string $sessionId, string $listId, string $personId, ?string $structureId, ?string $level, bool $afterFreeze, ?string $reason, string $now): string
    {
        return $this->repository->insert('pp_exam_candidates', [
            'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'session_id' => $sessionId, 'list_id' => $listId, 'person_id' => $personId,
            'structure_id' => $structureId, 'level_code' => $level, 'candidate_state' => 'proposed', 'added_after_freeze' => $afterFreeze ? 1 : 0,
            'added_reason' => $reason, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
