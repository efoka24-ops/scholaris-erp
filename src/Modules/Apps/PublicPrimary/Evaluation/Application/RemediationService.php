<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Evaluation\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Apps\PublicPrimary\Contracts\Referential;
use Scholaris\Modules\Apps\PublicPrimary\Evaluation\Infrastructure\PdoEvaluation;
use Scholaris\Modules\Apps\PublicPrimary\Support\Input;
use Scholaris\Modules\Apps\PublicPrimary\Support\PrimaryClock;
use Scholaris\Modules\Apps\PublicPrimary\Support\Rules;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;

/**
 * Remédiation tracée (arrêté conjoint du 14 août 2026 : registre de remédiation et fiche de suivi individualisée OBLIGATOIRES ; au
 * moins `public_primary.remediation.minutes_per_week` minutes par semaine et par discipline fondamentale ; formes
 * `public_primary.remediation.forms`).
 *  - registre : séances tenues (date, durée, forme, discipline, critères, élèves) ;
 *  - suivi de chaque remédiation née d'une unité (ouverte, en cours, résolue, forme, observation) ;
 *  - fiche de suivi individualisée d'un élève : ses remédiations et les séances suivies ;
 *  - bilan hebdomadaire d'une classe : minutes par discipline clé comparées au minimum.
 */
final class RemediationService
{
    public const STATES = ['open', 'in_progress', 'resolved'];

    public function __construct(
        private readonly PdoEvaluation $repository,
        private readonly Referential $referential,
        private readonly ClassRoster $classes,
        private readonly ContextResolver $contexts,
        private readonly Rules $rules,
        private readonly PrimaryClock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input structure_id, frame_id, unit_id?, held_on, duration_minutes, form, criterion_ids[], person_ids[], notes?
     * @return array<string, mixed>
     */
    public function recordSession(Principal $principal, string $establishmentId, array $input): array
    {
        $tenantId = $principal->tenantId;
        $errors = [];
        $structureId = (string) ($input['structure_id'] ?? '');
        $pupils = $this->classes->pupilIdsIn($tenantId, $establishmentId, $structureId, $this->clock->today());
        $frameId = $input['frame_id'] ?? null;
        $frame = is_string($frameId) ? $this->referential->frame($tenantId, $frameId) : null;
        if ($frame === null) {
            $errors['frame_id'][] = 'Discipline du référentiel requise.';
        }
        $unitId = $input['unit_id'] ?? null;
        if ($unitId !== null && (!is_string($unitId) || $this->referential->unit($tenantId, $unitId) === null)) {
            $errors['unit_id'][] = 'Unité inconnue.';
        }
        $heldOn = $input['held_on'] ?? null;
        if (!Input::isDate($heldOn) || $heldOn > $this->clock->today()) {
            $errors['held_on'][] = 'Date AAAA-MM-JJ, passée ou du jour, requise.';
        }
        $minutes = $input['duration_minutes'] ?? null;
        if (!is_int($minutes) || $minutes < 1 || $minutes > 600) {
            $errors['duration_minutes'][] = 'Durée en minutes (1 à 600) requise.';
        }
        $forms = $this->forms($tenantId, $establishmentId);
        $form = $input['form'] ?? null;
        if (!is_string($form) || !in_array($form, $forms, true)) {
            $errors['form'][] = 'Forme attendue : ' . implode(', ', $forms) . '.';
        }
        $criterionIds = self::ids($input['criterion_ids'] ?? [], 'criterion_ids', $errors);
        if ($frame !== null) {
            $known = array_map(static fn ($c): string => $c->id, $frame->criteria);
            if (array_diff($criterionIds, $known) !== []) {
                $errors['criterion_ids'][] = 'Critères hors de la discipline.';
            }
        }
        $personIds = self::ids($input['person_ids'] ?? null, 'person_ids', $errors);
        if ($personIds === []) {
            $errors['person_ids'][] = 'Au moins un élève.';
        } elseif (array_diff($personIds, $pupils) !== []) {
            $errors['person_ids'][] = 'Élèves hors de la classe.';
        }
        $notes = Input::text($input['notes'] ?? null, 500, 'notes', $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $id = $this->repository->insertSession($tenantId, $establishmentId, $structureId, (string) $frameId, is_string($unitId) ? $unitId : null, (string) $heldOn, (int) $minutes, (string) $form, $criterionIds, $personIds, $notes, $principal->userId, $this->clock->now());
        $this->audit->append($tenantId, $principal->userId, 'public_primary.remediation.session_recorded', 'pp_remediation_session', $id, null, ['structure_id' => $structureId, 'pupils' => count($personIds)]);

        return ['id' => $id, 'structure_id' => $structureId, 'frame_id' => $frameId, 'unit_id' => $unitId, 'held_on' => $heldOn, 'duration_minutes' => $minutes, 'form' => $form, 'criterion_ids' => $criterionIds, 'person_ids' => $personIds, 'notes' => $notes];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function sessionResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'structure_id' => (string) $row['structure_id'], 'frame_id' => (string) $row['frame_id'], 'unit_id' => $row['unit_id'],
            'held_on' => (string) $row['held_on'], 'duration_minutes' => (int) $row['duration_minutes'], 'form' => (string) $row['remediation_form'],
            'criterion_ids' => (array) json_decode((string) $row['criterion_ids'], true), 'person_ids' => (array) json_decode((string) $row['person_ids'], true),
            'notes' => $row['notes'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $input state, form?, outcome_note?
     * @return array<string, mixed>
     */
    public function update(Principal $principal, string $establishmentId, array $row, array $input): array
    {
        $errors = [];
        $state = $input['state'] ?? $row['remediation_state'];
        if (!is_string($state) || !in_array($state, self::STATES, true)) {
            $errors['state'][] = 'Valeur attendue : ' . implode(', ', self::STATES) . '.';
        }
        $form = $input['form'] ?? $row['remediation_form'];
        if ($form !== null && (!is_string($form) || !in_array($form, $this->forms($principal->tenantId, $establishmentId), true))) {
            $errors['form'][] = 'Forme de remédiation inconnue.';
        }
        $note = array_key_exists('outcome_note', $input) ? Input::text($input['outcome_note'], 500, 'outcome_note', $errors) : $row['outcome_note'];
        if ($state === 'resolved' && ($note === null || $note === '')) {
            $errors['outcome_note'][] = 'Une remédiation résolue porte une observation (fiche de suivi).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->repository->updateRemediation($principal->tenantId, (string) $row['id'], (string) $state, is_string($form) ? $form : null, is_string($note) ? $note : null, $principal->userId, $this->clock->now());
        $this->audit->append($principal->tenantId, $principal->userId, 'public_primary.remediation.updated', 'pp_remediation', (string) $row['id'], ['state' => $row['remediation_state']], ['state' => $state]);

        return self::remediationResource($this->repository->remediation($principal->tenantId, $establishmentId, (string) $row['id']) ?? $row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function remediationResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'unit_id' => (string) $row['unit_id'], 'structure_id' => (string) $row['structure_id'], 'person_id' => (string) $row['person_id'],
            'frame_id' => (string) $row['frame_id'], 'criterion_id' => (string) $row['criterion_id'], 'state' => (string) $row['remediation_state'],
            'form' => $row['remediation_form'], 'outcome_note' => $row['outcome_note'], 'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Fiche de suivi individualisée d'un élève (obligatoire, arrêté du 14 août 2026).
     *
     * @return array<string, mixed>
     */
    public function sheet(string $tenantId, string $establishmentId, string $personId, string $name): array
    {
        $items = [];
        foreach ($this->repository->remediationsOfPerson($tenantId, $establishmentId, $personId) as $row) {
            $frame = $this->referential->frame($tenantId, (string) $row['frame_id']);
            $unit = $this->referential->unit($tenantId, (string) $row['unit_id']);
            $criterion = null;
            foreach ($frame === null ? [] : $frame->criteria as $candidate) {
                if ($candidate->id === $row['criterion_id']) {
                    $criterion = $candidate;
                }
            }
            $items[] = self::remediationResource($row) + [
                'discipline_fr' => $frame?->labelFr, 'discipline_en' => $frame?->labelEn, 'unit_no' => $unit?->unitNo,
                'criterion_code' => $criterion?->code, 'criterion_fr' => $criterion?->labelFr, 'criterion_en' => $criterion?->labelEn,
            ];
        }
        $sessions = array_map(static fn (array $row): array => self::sessionResource($row), $this->repository->sessionsOfPerson($tenantId, $establishmentId, $personId));

        return [
            'person_id' => $personId, 'name' => $name, 'remediations' => $items, 'sessions' => $sessions,
            'minutes_attended' => array_sum(array_map(static fn (array $s): int => (int) $s['duration_minutes'], $sessions)),
            'open' => count(array_filter($items, static fn (array $i): bool => $i['state'] !== 'resolved')),
        ];
    }

    /**
     * Bilan hebdomadaire d'une classe (semaine du lundi au dimanche contenant `$day`) : minutes de remédiation par discipline clé du
     * niveau, comparées au minimum paramétré.
     *
     * @return array<string, mixed>
     */
    public function weeklySummary(string $tenantId, string $establishmentId, string $structureId, string $day): array
    {
        $monday = (new DateTimeImmutable($day))->modify('monday this week');
        $sunday = $monday->modify('+6 days');
        $minimum = $this->rules->int($tenantId, $establishmentId, 'public_primary.remediation.minutes_per_week');
        $class = $this->classes->find($tenantId, $establishmentId, $structureId) ?? throw HttpException::notFound();
        $subsystem = $this->contexts->subsystem($tenantId, $establishmentId);
        $minutes = [];
        foreach ($this->repository->sessions($tenantId, $establishmentId, $structureId, $monday->format('Y-m-d'), $sunday->format('Y-m-d'), null, 1000) as $row) {
            $minutes[(string) $row['frame_id']] = ($minutes[(string) $row['frame_id']] ?? 0) + (int) $row['duration_minutes'];
        }
        $lines = [];
        foreach ($class->levelCodes as $level) {
            foreach ($this->referential->framesFor($tenantId, $subsystem, $level, $sunday->format('Y-m-d')) as $frame) {
                if (!$frame->isKeyDiscipline) {
                    continue;
                }
                $done = $minutes[$frame->id] ?? 0;
                $lines[] = ['frame_id' => $frame->id, 'discipline_fr' => $frame->labelFr, 'discipline_en' => $frame->labelEn, 'minutes' => $done, 'minimum' => $minimum, 'met' => $done >= $minimum];
            }
        }

        return ['week_from' => $monday->format('Y-m-d'), 'week_to' => $sunday->format('Y-m-d'), 'minimum_minutes' => $minimum, 'disciplines' => $lines];
    }

    /** @return list<string> */
    public function forms(string $tenantId, string $establishmentId): array
    {
        return array_values(array_filter($this->rules->array($tenantId, $establishmentId, 'public_primary.remediation.forms'), 'is_string'));
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<string>
     */
    private static function ids(mixed $value, string $field, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[$field][] = 'Liste d\'identifiants attendue.';

            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_string($id) || $id === '' || strlen($id) > 40) {
                $errors[$field][] = 'Identifiant invalide.';

                return [];
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
