<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Remediation\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\PrimarySettings;
use Scholaris\Modules\Apps\Primary\Support\Store;

/**
 * Remédiation (arrêté du 14 août 2026, outils obligatoires) : REGISTRE des séances d'une classe (forme, discipline, durée, élèves
 * présents) avec le suivi hebdomadaire des minutes par discipline fondamentale (attendu : `primary.remediation.weekly_minutes`,
 * disciplines : `primary.remediation.fundamental_subjects`, valeurs paramétrées à confirmer) et FICHE DE SUIVI INDIVIDUALISÉE
 * (difficultés, actions, revues datées, état). Tout est borné au périmètre classe du maître.
 */
final class RemediationService
{
    public const SESSIONS = 'pri_remediation_sessions';
    public const PARTICIPANTS = 'pri_remediation_participants';
    public const PLANS = 'pri_remediation_plans';
    public const REVIEWS = 'pri_remediation_reviews';
    public const READ = ['primary.remediation.read', 'primary.remediation.write'];
    public const WRITE = ['primary.remediation.write'];
    public const STATUSES = ['open', 'in_progress', 'resolved'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly PrimarySettings $settings,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> registre de la classe et suivi hebdomadaire */
    public function register(Principal $principal, string $establishmentId, string $classId, ?string $from, ?string $to): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        $extra = '1 = 1';
        $params = [];
        if ($from !== null) {
            $extra .= ' AND held_on >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $extra .= ' AND held_on <= ?';
            $params[] = $to;
        }
        $sessions = $this->store->select(self::SESSIONS, $t, $establishmentId, ['class_structure_id' => $classId], 'held_on DESC, id', 2000, $extra, $params);
        $participants = $this->participantsOf($t, $establishmentId, array_map(static fn (array $s): string => (string) $s['id'], $sessions));
        $names = $this->classes->names($t, array_values(array_unique(array_merge(...array_values($participants ?: [[]])))));
        $items = [];
        $weeks = [];
        foreach ($sessions as $session) {
            $people = $participants[(string) $session['id']] ?? [];
            $items[] = self::sessionResource($session) + ['participants' => array_map(static fn (string $id): array => ['person_id' => $id, 'name' => $names[$id] ?? $id], $people)];
            $week = (new \DateTimeImmutable((string) $session['held_on']))->format('o-\WW');
            $subject = (string) ($session['subject_code'] ?? $session['subject_label']);
            $weeks[$week][$subject] = ($weeks[$week][$subject] ?? 0) + (int) $session['duration_minutes'];
        }
        $target = $this->settings->int($t, $establishmentId, 'primary.remediation.weekly_minutes');
        $fundamentals = $this->settings->strings($t, $establishmentId, 'primary.remediation.fundamental_subjects');
        $followUp = [];
        krsort($weeks);
        foreach ($weeks as $week => $subjects) {
            $rows = [];
            foreach (array_values(array_unique([...$fundamentals, ...array_keys($subjects)])) as $subject) {
                $minutes = $subjects[$subject] ?? 0;
                $rows[] = ['subject' => (string) $subject, 'minutes' => $minutes, 'fundamental' => in_array($subject, $fundamentals, true), 'meets_target' => $minutes >= $target];
            }
            $followUp[] = ['week' => (string) $week, 'subjects' => $rows];
        }

        return [
            'class' => ['id' => $classId, 'name' => $context['class']->name],
            'weekly_minutes_target' => $target, 'fundamental_subjects' => $fundamentals,
            'forms' => $this->forms($t, $establishmentId),
            'learners' => array_map(static fn (string $id): array => ['person_id' => $id, 'name' => $names[$id] ?? $id], $context['learner_ids']),
            'sessions' => $items, 'weekly' => $followUp,
        ];
    }

    /**
     * @param array<string, mixed> $input held_on, duration_minutes, remediation_form, subject_code, subject_label, objective, notes, period_id, person_ids
     * @return array<string, mixed>
     */
    public function createSession(Principal $principal, string $establishmentId, string $classId, array $input): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $this->access->assertWritableClass($principal, $perimeter, $classId, self::SESSIONS, $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        if (!array_key_exists((string) $input['remediation_form'], $this->forms($t, $establishmentId))) {
            throw HttpException::validation(['remediation_form' => ['Valeurs possibles : ' . implode(', ', array_keys($this->forms($t, $establishmentId))) . '.']]);
        }
        $people = (array) ($input['person_ids'] ?? []);
        $unknown = array_diff($people, $context['learner_ids']);
        if ($people === [] || $unknown !== []) {
            throw HttpException::validation(['person_ids' => [$people === [] ? 'Au moins un élève est attendu.' : 'Élève(s) non inscrit(s) dans cette classe.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->transaction(function () use ($t, $establishmentId, $classId, $input, $people, $principal, $now, $id): void {
            $this->store->insert(self::SESSIONS, $t, $establishmentId, [
                'id' => $id, 'class_structure_id' => $classId, 'period_id' => $input['period_id'] ?? null, 'subject_code' => $input['subject_code'] ?? null,
                'subject_label' => (string) $input['subject_label'], 'held_on' => (string) $input['held_on'], 'duration_minutes' => (int) $input['duration_minutes'],
                'remediation_form' => (string) $input['remediation_form'], 'objective' => (string) $input['objective'], 'notes' => $input['notes'] ?? null,
                'recorded_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($people as $personId) {
                $this->store->insert(self::PARTICIPANTS, $t, $establishmentId, ['id' => (string) new Ulid(), 'session_id' => $id, 'person_id' => (string) $personId, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
        $this->access->audit($principal, 'primary.remediation.session_recorded', self::SESSIONS, $id, null, ['class_structure_id' => $classId, 'learners' => count($people)]);

        return self::sessionResource($this->store->findOrFail(self::SESSIONS, $t, $establishmentId, $id)) + ['participants' => array_map(static fn ($p): array => ['person_id' => (string) $p], $people)];
    }

    /** @return list<array<string, mixed>> fiches de la classe (ou d'un élève) */
    public function plans(Principal $principal, string $establishmentId, ?string $classId, ?string $personId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $where = [];
        if ($classId !== null) {
            $this->access->assertReadableClass($perimeter, $classId);
            $where['class_structure_id'] = $classId;
        }
        if ($personId !== null) {
            $where['person_id'] = $personId;
        }
        if ($where === []) {
            throw HttpException::validation(['class_id' => ['Indiquez une classe ou un élève.']]);
        }
        $t = $principal->tenantId;
        $rows = array_values(array_filter(
            $this->store->select(self::PLANS, $t, $establishmentId, $where, 'opened_on DESC, id', 2000),
            static fn (array $r): bool => $perimeter->coversClass((string) $r['class_structure_id']),
        ));
        $names = $this->classes->names($t, array_values(array_unique(array_map(static fn (array $r): string => (string) $r['person_id'], $rows))));

        return array_map(static fn (array $r): array => self::planResource($r) + ['name' => $names[(string) $r['person_id']] ?? (string) $r['person_id']], $rows);
    }

    /** @return array<string, mixed> fiche de suivi et ses revues */
    public function plan(Principal $principal, string $establishmentId, string $planId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::PLANS, $t, $establishmentId, $planId, 'Fiche de suivi introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $row['class_structure_id']);
        $names = $this->classes->names($t, [(string) $row['person_id']]);
        $reviews = array_map(self::reviewResource(...), $this->store->select(self::REVIEWS, $t, $establishmentId, ['plan_id' => $planId], 'reviewed_on, id', 500));
        $sessions = 0;
        $minutes = 0;
        $participations = $this->store->select(self::PARTICIPANTS, $t, $establishmentId, ['person_id' => (string) $row['person_id']], 'id', 2000);
        foreach ($participations as $p) {
            $session = $this->store->find(self::SESSIONS, $t, $establishmentId, (string) $p['session_id']);
            if ($session !== null && (string) $session['held_on'] >= (string) $row['opened_on'] && ($row['subject_code'] === null || $session['subject_code'] === $row['subject_code'])) {
                ++$sessions;
                $minutes += (int) $session['duration_minutes'];
            }
        }

        return self::planResource($row) + ['name' => $names[(string) $row['person_id']] ?? (string) $row['person_id'], 'reviews' => $reviews, 'sessions_attended' => $sessions, 'minutes_attended' => $minutes];
    }

    /**
     * @param array<string, mixed> $input person_id, class_id, subject_code, subject_label, difficulties, actions, period_id, opened_on
     * @return array<string, mixed>
     */
    public function createPlan(Principal $principal, string $establishmentId, array $input): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $classId = (string) $input['class_id'];
        $this->access->assertWritableClass($principal, $perimeter, $classId, self::PLANS, $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        if (!in_array((string) $input['person_id'], $context['learner_ids'], true)) {
            throw HttpException::validation(['person_id' => ['Cet élève n\'est pas inscrit dans cette classe.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::PLANS, $t, $establishmentId, [
            'id' => $id, 'person_id' => (string) $input['person_id'], 'class_structure_id' => $classId, 'period_id' => $input['period_id'] ?? null,
            'subject_code' => $input['subject_code'] ?? null, 'subject_label' => (string) $input['subject_label'], 'difficulties' => (string) $input['difficulties'],
            'actions' => (string) $input['actions'], 'status' => 'open', 'opened_on' => (string) ($input['opened_on'] ?? $this->clock->today()), 'closed_on' => null,
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.remediation.plan_opened', self::PLANS, $id, null, ['person_id' => (string) $input['person_id']]);

        return $this->plan($principal, $establishmentId, $id);
    }

    /**
     * @param array<string, mixed> $input reviewed_on, progress_note, status_after, actions
     * @return array<string, mixed>
     */
    public function review(Principal $principal, string $establishmentId, string $planId, array $input): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::WRITE);
        $t = $principal->tenantId;
        $row = $this->store->findOrFail(self::PLANS, $t, $establishmentId, $planId, 'Fiche de suivi introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $row['class_structure_id'], self::PLANS, $planId);
        if ($row['status'] === 'resolved') {
            throw HttpException::conflict('Cette fiche est close : ouvrez une nouvelle fiche si la difficulté réapparaît.');
        }
        $now = $this->clock->now();
        $status = (string) $input['status_after'];
        $day = (string) ($input['reviewed_on'] ?? $this->clock->today());
        $this->store->transaction(function () use ($t, $establishmentId, $planId, $input, $principal, $now, $status, $day): void {
            $this->store->insert(self::REVIEWS, $t, $establishmentId, [
                'id' => (string) new Ulid(), 'plan_id' => $planId, 'reviewed_on' => $day, 'progress_note' => (string) $input['progress_note'],
                'status_after' => $status, 'reviewed_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $set = ['status' => $status, 'closed_on' => $status === 'resolved' ? $day : null, 'updated_at' => $now];
            if (isset($input['actions'])) {
                $set['actions'] = (string) $input['actions'];
            }
            $this->store->update(self::PLANS, $t, $establishmentId, $set, ['id' => $planId]);
        });
        $this->access->audit($principal, 'primary.remediation.plan_reviewed', self::PLANS, $planId, ['status' => (string) $row['status']], ['status' => $status]);

        return $this->plan($principal, $establishmentId, $planId);
    }

    /**
     * Synthèse pour le bulletin : séances suivies sur la période et disciplines des fiches ouvertes.
     *
     * @return array{sessions: int, minutes: int, open_plans: list<string>}
     */
    public function learnerSummary(string $tenantId, string $establishmentId, string $personId, string $from, string $to): array
    {
        $sessions = 0;
        $minutes = 0;
        foreach ($this->store->select(self::PARTICIPANTS, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 2000) as $p) {
            $session = $this->store->find(self::SESSIONS, $tenantId, $establishmentId, (string) $p['session_id']);
            if ($session !== null && (string) $session['held_on'] >= $from && (string) $session['held_on'] <= $to) {
                ++$sessions;
                $minutes += (int) $session['duration_minutes'];
            }
        }
        $open = array_map(static fn (array $r): string => (string) $r['subject_label'], $this->store->select(self::PLANS, $tenantId, $establishmentId, ['person_id' => $personId], 'opened_on', 100, "status <> 'resolved'"));

        return ['sessions' => $sessions, 'minutes' => $minutes, 'open_plans' => array_values(array_unique($open))];
    }

    /** @return array<string, array<string, mixed>> */
    public function forms(string $tenantId, string $establishmentId): array
    {
        return $this->settings->labels($tenantId, $establishmentId, 'primary.remediation.forms');
    }

    /**
     * @param list<string> $sessionIds
     * @return array<string, list<string>>
     */
    private function participantsOf(string $tenantId, string $establishmentId, array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $wanted = array_flip($sessionIds);
        $out = [];
        $marks = implode(', ', array_fill(0, count($sessionIds), '?'));
        foreach ($this->store->select(self::PARTICIPANTS, $tenantId, $establishmentId, [], 'session_id, id', 50000, 'session_id IN (' . $marks . ')', $sessionIds) as $row) {
            if (isset($wanted[(string) $row['session_id']])) {
                $out[(string) $row['session_id']][] = (string) $row['person_id'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sessionResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'class_structure_id' => (string) $row['class_structure_id'], 'period_id' => $row['period_id'] === null ? null : (string) $row['period_id'],
            'subject_code' => $row['subject_code'] === null ? null : (string) $row['subject_code'], 'subject_label' => (string) $row['subject_label'],
            'held_on' => (string) $row['held_on'], 'duration_minutes' => (int) $row['duration_minutes'], 'remediation_form' => (string) $row['remediation_form'],
            'objective' => (string) $row['objective'], 'notes' => $row['notes'] === null ? null : (string) $row['notes'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function planResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'class_structure_id' => (string) $row['class_structure_id'],
            'period_id' => $row['period_id'] === null ? null : (string) $row['period_id'], 'subject_code' => $row['subject_code'] === null ? null : (string) $row['subject_code'],
            'subject_label' => (string) $row['subject_label'], 'difficulties' => (string) $row['difficulties'], 'actions' => (string) $row['actions'],
            'status' => (string) $row['status'], 'opened_on' => (string) $row['opened_on'], 'closed_on' => $row['closed_on'] === null ? null : (string) $row['closed_on'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function reviewResource(array $row): array
    {
        return ['id' => (string) $row['id'], 'reviewed_on' => (string) $row['reviewed_on'], 'progress_note' => (string) $row['progress_note'], 'status_after' => (string) $row['status_after']];
    }
}
