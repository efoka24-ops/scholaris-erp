<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Apps\Secondary\Workshops\Contracts\PracticalGrid;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Ateliers, postes, fiches pratiques, séances (RG-ATL-1), registre de sécurité, matière d'œuvre et évaluation pratique (T908,
 * T909). Toute ligne est cloisonnée par client et établissement ; la classe d'une séance est une classe du secondaire de
 * l'établissement et ses élèves y sont inscrits.
 */
final class WorkshopService
{
    public const WORKSHOPS = 'se_workshops';
    public const WORKSTATIONS = 'se_workstations';
    public const SHEETS = 'se_practical_sheets';
    public const SESSIONS = 'se_workshop_sessions';
    public const ITEMS = 'se_material_items';
    public const MOVES = 'se_material_moves';
    public const SAFETY = 'se_safety_events';
    public const EVALUATIONS = 'se_practical_evaluations';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SecondaryClock $clock,
        private readonly PracticalGrid $grid,
        private readonly ClassProfiles $classes,
        private readonly EnrollmentDirectory $enrollments,
        private readonly ScopedByClass $scoped,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createWorkshop(Principal $principal, string $establishmentId, Perimeter $perimeter, array $data): array
    {
        $this->access->assertWhole($principal, $perimeter, 'workshop');
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::WORKSHOPS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'stream_id' => $data['stream_id'] ?? null, 'room_id' => $data['room_id'] ?? null, 'label' => $data['label'],
            'capacity' => $data['capacity'] ?? null, 'chief_person_id' => $data['chief_person_id'] ?? null, 'created_by' => $principal->userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.workshop.created', 'workshop', $id, null, ['label' => $data['label']]);

        return $this->workshop($principal->tenantId, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateWorkshop(Principal $principal, string $establishmentId, Perimeter $perimeter, string $id, array $data): array
    {
        $this->access->assertWhole($principal, $perimeter, 'workshop');
        $before = $this->workshop($principal->tenantId, $establishmentId, $id);
        if ($data === []) {
            throw HttpException::validation(['workshop' => ['Aucun champ modifiable fourni.']]);
        }
        $this->store->update(self::WORKSHOPS, $principal->tenantId, $establishmentId, $data + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $after = $this->workshop($principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.workshop.updated', 'workshop', $id, $before, $after);

        return $after;
    }

    /** @return array<string, mixed> */
    public function workshop(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::WORKSHOPS, $tenantId, $establishmentId, $id, 'Atelier introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function workshops(string $tenantId, string $establishmentId): array
    {
        return $this->store->select(self::WORKSHOPS, $tenantId, $establishmentId, [], 'label', 500);
    }

    /**
     * @param list<string> $ppeRequired
     * @return array<string, mixed>
     */
    public function createWorkstation(Principal $principal, string $establishmentId, string $workshopId, string $label, bool $machine, array $ppeRequired): array
    {
        $this->workshop($principal->tenantId, $establishmentId, $workshopId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::WORKSTATIONS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'workshop_id' => $workshopId, 'label' => $label, 'machine' => (int) $machine,
            'ppe_required' => self::encode($ppeRequired), 'status' => 'active', 'created_by' => $principal->userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.workshop.workstation.created', 'workstation', $id, null, ['workshop_id' => $workshopId, 'machine' => $machine, 'ppe_required' => $ppeRequired]);

        return $this->store->findOrFail(self::WORKSTATIONS, $principal->tenantId, $establishmentId, $id);
    }

    /** @return list<array<string, mixed>> */
    public function workstations(string $tenantId, string $establishmentId, string $workshopId): array
    {
        $this->workshop($tenantId, $establishmentId, $workshopId);

        return $this->store->select(self::WORKSTATIONS, $tenantId, $establishmentId, ['workshop_id' => $workshopId], 'label', 500);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateWorkstation(Principal $principal, string $establishmentId, string $id, array $data): array
    {
        $before = $this->store->findOrFail(self::WORKSTATIONS, $principal->tenantId, $establishmentId, $id, 'Poste introuvable.');
        if ($data === []) {
            throw HttpException::validation(['workstation' => ['Aucun champ modifiable fourni.']]);
        }
        $this->store->update(self::WORKSTATIONS, $principal->tenantId, $establishmentId, $data + ['updated_at' => $this->clock->now()], ['id' => $id]);
        $after = $this->store->findOrFail(self::WORKSTATIONS, $principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.workshop.workstation.updated', 'workstation', $id, $before, $after);

        return $after;
    }

    /**
     * @param list<array{code: string, label: string, points: float|int}> $criteria
     * @param list<string> $materials
     * @param list<string> $tools
     * @param list<string> $safetyRules
     * @return array<string, mixed>
     */
    public function createSheet(Principal $principal, string $establishmentId, string $workshopId, string $title, string $objective, array $materials, array $tools, array $safetyRules, array $criteria, float $base, ?float $safetyCap): array
    {
        $this->workshop($principal->tenantId, $establishmentId, $workshopId);
        $this->score($criteria, $base, $safetyCap, [], false);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::SHEETS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'workshop_id' => $workshopId, 'title' => $title, 'objective' => $objective, 'materials' => self::encode($materials),
            'tools' => self::encode($tools), 'safety_rules' => self::encode($safetyRules), 'criteria' => self::encode($criteria),
            'base_score' => $base, 'safety_cap' => $safetyCap, 'version_no' => 1, 'created_by' => $principal->userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.workshop.sheet.created', 'practical_sheet', $id, null, ['workshop_id' => $workshopId, 'base_score' => $base, 'safety_cap' => $safetyCap]);

        return $this->sheet($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function sheet(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::SHEETS, $tenantId, $establishmentId, $id, 'Fiche pratique introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function sheets(string $tenantId, string $establishmentId, string $workshopId): array
    {
        $this->workshop($tenantId, $establishmentId, $workshopId);

        return $this->store->select(self::SHEETS, $tenantId, $establishmentId, ['workshop_id' => $workshopId], 'created_at DESC', 500);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateSheet(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $before = $this->sheet($principal->tenantId, $establishmentId, $id);
        if ($changes === []) {
            throw HttpException::validation(['sheet' => ['Aucun champ modifiable fourni.']]);
        }
        $criteria = isset($changes['criteria']) && is_array($changes['criteria']) ? $changes['criteria'] : self::decodeList((string) $before['criteria']);
        $base = (float) ($changes['base_score'] ?? $before['base_score']);
        $cap = array_key_exists('safety_cap', $changes) ? $changes['safety_cap'] : $before['safety_cap'];
        /** @var list<array{code: string, label: string, points: float|int}> $criteria */
        $this->score($criteria, $base, $cap === null ? null : (float) $cap, [], false);
        foreach (['materials', 'tools', 'safety_rules', 'criteria'] as $field) {
            if (isset($changes[$field]) && is_array($changes[$field])) {
                $changes[$field] = self::encode($changes[$field]);
            }
        }
        $changes['version_no'] = (int) $before['version_no'] + 1;
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(self::SHEETS, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $after = $this->sheet($principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.workshop.sheet.updated', 'practical_sheet', $id, $before, $after);

        return $after;
    }

    /**
     * Séance d'atelier (API-14). RG-ATL-1 : sans tous les EPI exigés par un poste machine, l'élève suit la séance en observation et
     * aucun poste ne lui est affecté. Rejeu du même `client_uuid` : la séance déjà enregistrée est rendue telle quelle.
     *
     * @param list<array{person_id: string, workstation_id?: ?string, ppe_items?: list<string>}> $learners
     * @return array{session: array<string, mixed>, replayed: bool}
     */
    public function createSession(Principal $principal, string $establishmentId, Perimeter $perimeter, string $workshopId, string $structureId, string $sheetId, string $date, string $teacherId, string $clientUuid, array $learners): array
    {
        $existing = $this->store->select(self::SESSIONS, $principal->tenantId, $establishmentId, ['client_uuid' => $clientUuid], 'id', 1)[0] ?? null;
        if ($existing !== null) {
            $this->access->assertReadableClass($perimeter, (string) $existing['structure_id']);

            return ['session' => self::sessionResource($existing), 'replayed' => true];
        }
        $this->access->assertWritableClass($principal, $perimeter, $structureId, 'workshop_session', $clientUuid);
        $class = $this->classes->find($principal->tenantId, $establishmentId, $structureId);
        if ($class === null) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }
        $workshop = $this->workshop($principal->tenantId, $establishmentId, $workshopId);
        $sheet = $this->sheet($principal->tenantId, $establishmentId, $sheetId);
        if ($sheet['workshop_id'] !== $workshopId) {
            throw HttpException::notFound('Fiche pratique introuvable dans cet atelier.');
        }
        $attendance = [];
        $seenLearners = [];
        $usedStations = [];
        foreach ($learners as $learner) {
            $personId = $learner['person_id'];
            if (isset($seenLearners[$personId])) {
                throw HttpException::validation(['learners' => ['Un élève ne figure qu’une fois dans la séance.']]);
            }
            $seenLearners[$personId] = true;
            if (!$this->enrolledIn($principal->tenantId, $establishmentId, $structureId, $class->academicYearId, $personId)) {
                throw HttpException::validation(['learners' => ['Élève non inscrit dans cette classe : ' . $personId . '.']]);
            }
            $workstationId = $learner['workstation_id'] ?? null;
            $provided = $learner['ppe_items'] ?? [];
            $mode = 'observation';
            $missing = [];
            if (is_string($workstationId) && $workstationId !== '') {
                $station = $this->store->findOrFail(self::WORKSTATIONS, $principal->tenantId, $establishmentId, $workstationId, 'Poste introuvable.');
                if ($station['workshop_id'] !== $workshopId || $station['status'] !== 'active') {
                    throw HttpException::validation(['workstation_id' => ['Le poste est indisponible dans cet atelier.']]);
                }
                $missing = (int) $station['machine'] === 1 ? array_values(array_diff(self::strings((string) $station['ppe_required']), $provided)) : [];
                if ($missing === []) {
                    if (isset($usedStations[$workstationId])) {
                        throw HttpException::validation(['workstation_id' => ['Un poste n’est affecté qu’à un élève par séance.']]);
                    }
                    $usedStations[$workstationId] = true;
                    $mode = 'workstation';
                } else {
                    $workstationId = null;
                }
            } else {
                $workstationId = null;
            }
            $attendance[] = ['person_id' => $personId, 'mode' => $mode, 'workstation_id' => $workstationId, 'ppe_items' => $provided, 'missing_ppe' => $missing];
        }
        $id = (string) new Ulid();
        $this->store->insert(self::SESSIONS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'workshop_id' => $workshop['id'], 'structure_id' => $structureId, 'sheet_id' => $sheetId, 'session_date' => $date,
            'teacher_person_id' => $teacherId, 'attendance' => self::encode($attendance), 'client_uuid' => $clientUuid,
            'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $observers = count(array_filter($attendance, static fn (array $entry): bool => $entry['mode'] === 'observation'));
        $this->access->audit($principal, 'secondary.workshop.session.created', 'workshop_session', $id, null, ['workshop_id' => $workshopId, 'learner_count' => count($attendance), 'observation_count' => $observers]);

        return ['session' => self::sessionResource($this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $id)), 'replayed' => false];
    }

    /** @return list<array<string, mixed>> séances de l'atelier dans le périmètre de classes du lecteur */
    public function sessions(string $tenantId, string $establishmentId, Perimeter $perimeter, string $workshopId): array
    {
        $this->workshop($tenantId, $establishmentId, $workshopId);
        [$extra, $params] = $this->scoped->sqlFilter($perimeter, 'structure_id');

        return array_map(self::sessionResource(...), $this->store->select(self::SESSIONS, $tenantId, $establishmentId, ['workshop_id' => $workshopId], 'session_date DESC', 500, $extra, $params));
    }

    /** @return array<string, mixed> */
    public function session(string $tenantId, string $establishmentId, string $id): array
    {
        return self::sessionResource($this->store->findOrFail(self::SESSIONS, $tenantId, $establishmentId, $id, 'Séance introuvable.'));
    }

    /** @return array<string, mixed> */
    public function createItem(Principal $principal, string $establishmentId, string $workshopId, string $label, string $unit, int $unitCost, float $stock): array
    {
        $this->workshop($principal->tenantId, $establishmentId, $workshopId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::ITEMS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'workshop_id' => $workshopId, 'label' => $label, 'unit_label' => $unit, 'unit_cost' => $unitCost,
            'stock_qty' => $stock, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.workshop.material.created', 'material_item', $id, null, ['workshop_id' => $workshopId, 'unit_cost' => $unitCost, 'stock_qty' => $stock]);

        return $this->item($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function item(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::ITEMS, $tenantId, $establishmentId, $id, 'Article de matière d’œuvre introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function items(string $tenantId, string $establishmentId, string $workshopId): array
    {
        $this->workshop($tenantId, $establishmentId, $workshopId);

        return $this->store->select(self::ITEMS, $tenantId, $establishmentId, ['workshop_id' => $workshopId], 'label', 500);
    }

    /**
     * Mouvement de stock. Une sortie rattachée à une séance donne aussi le coût par élève présent (T909) : coût total divisé par
     * le nombre d'élèves de la séance, arrondi au franc. Aucun frais n'est demandé aux familles (RG-ATL-2).
     *
     * @return array<string, mixed>
     */
    public function moveItem(Principal $principal, string $establishmentId, string $itemId, string $kind, float $quantity, ?string $sessionId): array
    {
        return $this->store->transaction(function () use ($principal, $establishmentId, $itemId, $kind, $quantity, $sessionId): array {
            $item = $this->item($principal->tenantId, $establishmentId, $itemId);
            $next = round((float) $item['stock_qty'] + ($kind === 'in' ? $quantity : -$quantity), 2);
            if ($next < 0.0) {
                throw HttpException::validation(['quantity' => ['Le stock ne peut pas devenir négatif.']]);
            }
            $learners = null;
            if ($sessionId !== null) {
                $session = $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $sessionId, 'Séance introuvable.');
                if ($session['workshop_id'] !== $item['workshop_id']) {
                    throw HttpException::notFound('Séance introuvable dans cet atelier.');
                }
                $learners = count(self::decodeList((string) $session['attendance']));
            }
            $this->store->update(self::ITEMS, $principal->tenantId, $establishmentId, ['stock_qty' => $next, 'updated_at' => $this->clock->now()], ['id' => $itemId]);
            $id = (string) new Ulid();
            $this->store->insert(self::MOVES, $principal->tenantId, $establishmentId, [
                'id' => $id, 'item_id' => $itemId, 'move_kind' => $kind, 'quantity' => $quantity, 'session_id' => $sessionId,
                'unit_cost' => (int) $item['unit_cost'], 'moved_by' => $principal->userId, 'created_at' => $this->clock->now(),
            ]);
            $this->access->audit($principal, 'secondary.workshop.material.moved', 'material_move', $id, null, ['item_id' => $itemId, 'move_kind' => $kind, 'quantity' => $quantity, 'session_id' => $sessionId]);
            $total = (int) round($quantity * (int) $item['unit_cost']);

            return [
                'move' => $this->store->findOrFail(self::MOVES, $principal->tenantId, $establishmentId, $id), 'stock_qty' => $next, 'total_cost_xaf' => $total,
                'learner_count' => $learners, 'cost_per_learner_xaf' => $learners === null || $learners === 0 ? null : (int) round($total / $learners),
            ];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function safetyEvent(Principal $principal, string $establishmentId, string $workshopId, array $input): array
    {
        $this->workshop($principal->tenantId, $establishmentId, $workshopId);
        if (isset($input['workstation_id'])) {
            $station = $this->store->findOrFail(self::WORKSTATIONS, $principal->tenantId, $establishmentId, (string) $input['workstation_id'], 'Poste introuvable.');
            if ($station['workshop_id'] !== $workshopId) {
                throw HttpException::notFound('Poste introuvable dans cet atelier.');
            }
        }
        if (isset($input['session_id'])) {
            $session = $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, (string) $input['session_id'], 'Séance introuvable.');
            if ($session['workshop_id'] !== $workshopId) {
                throw HttpException::notFound('Séance introuvable dans cet atelier.');
            }
        }
        $id = (string) new Ulid();
        $this->store->insert(self::SAFETY, $principal->tenantId, $establishmentId, [
            'id' => $id, 'workshop_id' => $workshopId, 'session_id' => $input['session_id'] ?? null, 'event_kind' => $input['event_kind'],
            'person_id' => $input['person_id'] ?? null, 'workstation_id' => $input['workstation_id'] ?? null, 'description' => $input['description'],
            'occurred_at' => $input['occurred_at'] ?? $this->clock->now(), 'reported_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'secondary.workshop.safety.recorded', 'safety_event', $id, null, ['workshop_id' => $workshopId, 'event_kind' => $input['event_kind']]);

        return $this->store->findOrFail(self::SAFETY, $principal->tenantId, $establishmentId, $id);
    }

    /** @return list<array<string, mixed>> */
    public function safetyEvents(string $tenantId, string $establishmentId, string $workshopId): array
    {
        $this->workshop($tenantId, $establishmentId, $workshopId);

        return $this->store->select(self::SAFETY, $tenantId, $establishmentId, ['workshop_id' => $workshopId], 'occurred_at DESC', 500);
    }

    /**
     * Évaluation pratique d'un élève affecté à un poste : grille de la fiche → note sur la base paramétrée, plafond paramétré en cas
     * de manquement grave. Une seule évaluation par élève et par séance. Faute de contrat d'écriture du socle Assessment, la note
     * reste ici (`assessment_id` nul) et se lit par la séance.
     *
     * @param array<string, float|int> $observed
     * @return array<string, mixed>
     */
    public function evaluate(Principal $principal, string $establishmentId, string $sessionId, string $personId, array $observed, bool $seriousBreach): array
    {
        $session = $this->store->findOrFail(self::SESSIONS, $principal->tenantId, $establishmentId, $sessionId, 'Séance introuvable.');
        $learner = null;
        foreach (self::decodeList((string) $session['attendance']) as $entry) {
            if (($entry['person_id'] ?? null) === $personId && ($entry['mode'] ?? null) === 'workstation') {
                $learner = $entry;
                break;
            }
        }
        if ($learner === null) {
            throw HttpException::validation(['person_id' => ['Seul un élève affecté à un poste peut recevoir une évaluation pratique.']]);
        }
        if ($this->store->count(self::EVALUATIONS, $principal->tenantId, $establishmentId, ['session_id' => $sessionId, 'person_id' => $personId]) > 0) {
            throw HttpException::conflict('Cet élève a déjà une évaluation pratique pour cette séance.');
        }
        $sheet = $this->sheet($principal->tenantId, $establishmentId, (string) $session['sheet_id']);
        /** @var list<array{code: string, label: string, points: float|int}> $criteria */
        $criteria = self::decodeList((string) $sheet['criteria']);
        $result = $this->score($criteria, (float) $sheet['base_score'], $sheet['safety_cap'] === null ? null : (float) $sheet['safety_cap'], $observed, $seriousBreach);
        $id = (string) new Ulid();
        $this->store->insert(self::EVALUATIONS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'workshop_id' => $session['workshop_id'], 'session_id' => $sessionId, 'sheet_id' => $sheet['id'], 'person_id' => $personId,
            'criteria_scores' => self::encode($observed), 'raw_total' => $result['uncapped'], 'serious_safety_breach' => (int) $seriousBreach,
            'final_score' => $result['score'], 'assessment_id' => null, 'evaluated_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'secondary.workshop.practical.evaluated', 'practical_evaluation', $id, null, ['session_id' => $sessionId, 'person_id' => $personId, 'score' => $result['score'], 'capped' => $result['capped']]);

        return $this->store->findOrFail(self::EVALUATIONS, $principal->tenantId, $establishmentId, $id) + ['score_details' => $result];
    }

    /**
     * @param list<array{code: string, label: string, points: float|int}> $criteria
     * @param array<string, float|int> $observed
     * @return array{score: float, uncapped: float, capped: bool, observed: float, total: float}
     */
    private function score(array $criteria, float $base, ?float $cap, array $observed, bool $breach): array
    {
        try {
            return $this->grid->score($criteria, $base, $cap, $observed, $breach);
        } catch (\InvalidArgumentException $error) {
            throw HttpException::validation(['criteria' => [$error->getMessage()]]);
        }
    }

    private function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $yearId, string $personId): bool
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId === $structureId
                && $enrollment->academicYearId === $yearId && $enrollment->status === EnrollmentInfo::ACTIVE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sessionResource(array $row): array
    {
        $row['attendance'] = self::decodeList((string) $row['attendance']);

        return $row;
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private static function strings(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return list<array<string, mixed>> */
    private static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
