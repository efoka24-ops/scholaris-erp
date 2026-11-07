<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Grades\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Primary\Academic\Contracts\CompetencyDirectory;
use Scholaris\Modules\Apps\Primary\Support\Access;
use Scholaris\Modules\Apps\Primary\Support\ClassRoster;
use Scholaris\Modules\Apps\Primary\Support\PrimaryClock;
use Scholaris\Modules\Apps\Primary\Support\ScaleProvider;
use Scholaris\Modules\Apps\Primary\Support\Store;

/**
 * Feuille de compétences d'une classe pour une période (EX-PRI-20) : saisie par lot, idempotente par `client_uuid` (rejeu hors ligne
 * sans doublon), contrôle de version (jamais d'écrasement : version périmée = 409), verrouillage par la direction, publication aux
 * familles et corrections APPROUVÉES après verrouillage, avec historique. Les codes de l'échelle viennent des paramètres de l'école.
 */
final class CompetencySheetService
{
    public const MARKS = 'pri_competency_marks';
    public const EVENTS = 'pri_competency_mark_events';
    public const STATES = 'pri_sheet_states';
    public const CORRECTIONS = 'pri_competency_corrections';
    public const READ = ['primary.competency.read', 'primary.competency.enter', 'primary.competency.validate'];
    public const ENTER = ['primary.competency.enter'];
    public const VALIDATE = ['primary.competency.validate'];
    public const APPROVE = ['assessment.correction.approve'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $classes,
        private readonly CompetencyDirectory $competencies,
        private readonly ScaleProvider $scales,
        private readonly PrimaryClock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function sheet(Principal $principal, string $establishmentId, string $classId, string $periodId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->access->assertReadableClass($perimeter, $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        $state = $this->state($t, $establishmentId, $classId, $periodId);
        $competencies = [];
        $domains = [];
        if ($context['level_id'] !== null && $context['year_id'] !== null) {
            $domains = $this->competencies->domainsOf($t, $establishmentId, $context['year_id']);
            foreach ($this->competencies->forLevel($t, $establishmentId, $context['year_id'], $context['level_id']) as $c) {
                $competencies[] = ['id' => $c->id, 'code' => $c->code, 'label_fr' => $c->labelFr, 'label_en' => $c->labelEn, 'domain_id' => $c->domainId, 'subject_id' => $c->subjectId];
            }
        }
        $names = $this->classes->names($t, $context['learner_ids']);
        $learners = array_map(static fn (string $id): array => ['person_id' => $id, 'name' => $names[$id] ?? $id], $context['learner_ids']);
        usort($learners, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $marks = array_map(self::markResource(...), $this->store->select(self::MARKS, $t, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $periodId], 'person_id, competency_id', 20000));

        return [
            'class' => ['id' => $classId, 'name' => $context['class']->name], 'period_id' => $periodId,
            'status' => $state === null ? 'open' : (string) $state['status'], 'published' => $state !== null && $state['published_at'] !== null,
            'scale' => $this->scales->qualitative($t, $establishmentId), 'absence_codes' => $this->scales->absenceCodes($t, $establishmentId),
            'domains' => array_map(static fn (string $id, array $d): array => ['id' => $id] + $d, array_keys($domains), array_values($domains)),
            'competencies' => $competencies, 'learners' => $learners, 'marks' => $marks,
        ];
    }

    /**
     * Enregistre un lot. Tout ou rien : un conflit de version annule le lot entier (409, aucune écriture) et rend les versions serveur.
     *
     * @param list<array<string, mixed>> $entries person_id, competency_id, scale_code, absence_code, remark, client_uuid, version
     * @return array{saved: int, duplicates: int, unchanged: int, marks: list<array<string, mixed>>}
     */
    public function save(Principal $principal, string $establishmentId, string $classId, string $periodId, array $entries): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::ENTER);
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'pri_competency_sheet', $classId);
        $t = $principal->tenantId;
        $context = $this->classes->resolve($t, $establishmentId, $classId);
        $state = $this->state($t, $establishmentId, $classId, $periodId);
        if ($state !== null && $state['status'] !== 'open') {
            throw HttpException::conflict('La feuille est verrouillée : une modification passe par une correction approuvée par la direction.');
        }
        if ($entries === []) {
            throw HttpException::validation(['entries' => ['Au moins une évaluation est attendue.']]);
        }
        $levelCompetencies = [];
        if ($context['level_id'] !== null && $context['year_id'] !== null) {
            foreach ($this->competencies->forLevel($t, $establishmentId, $context['year_id'], $context['level_id']) as $c) {
                $levelCompetencies[$c->id] = true;
            }
        }
        $codes = $this->scales->codes($t, $establishmentId);
        $absences = $this->scales->absenceCodes($t, $establishmentId);
        $learners = array_flip($context['learner_ids']);

        $errors = [];
        foreach ($entries as $i => $entry) {
            $person = (string) ($entry['person_id'] ?? '');
            $competency = (string) ($entry['competency_id'] ?? '');
            $scale = $entry['scale_code'] ?? null;
            $absence = $entry['absence_code'] ?? null;
            if (!isset($learners[$person])) {
                $errors["entries.$i.person_id"] = ['Cet élève n\'est pas inscrit dans cette classe.'];
            }
            if (!isset($levelCompetencies[$competency])) {
                $errors["entries.$i.competency_id"] = ['Cette compétence n\'appartient pas au niveau de la classe.'];
            }
            if ($scale !== null && !in_array($scale, $codes, true)) {
                $errors["entries.$i.scale_code"] = ['Valeur possible : ' . implode(', ', $codes) . '.'];
            }
            if ($absence !== null && !in_array($absence, $absences, true)) {
                $errors["entries.$i.absence_code"] = ['Valeur possible : ' . implode(', ', $absences) . '.'];
            }
            if ($scale !== null && $absence !== null) {
                $errors["entries.$i.scale_code"] = ['Une évaluation ne peut pas être à la fois notée et marquée absente.'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $saved = 0;
        $duplicates = 0;
        $unchanged = 0;
        $touched = [];
        $conflicts = [];
        $this->store->transaction(function () use ($principal, $establishmentId, $classId, $periodId, $entries, &$saved, &$duplicates, &$unchanged, &$touched, &$conflicts): void {
            $t = $principal->tenantId;
            foreach ($entries as $entry) {
                $uuid = isset($entry['client_uuid']) ? (string) $entry['client_uuid'] : null;
                if ($uuid !== null && $this->store->select(self::EVENTS, $t, $establishmentId, ['client_uuid' => $uuid], 'id', 1) !== []) {
                    ++$duplicates;
                    continue;
                }
                $existing = $this->store->select(self::MARKS, $t, $establishmentId, ['person_id' => (string) $entry['person_id'], 'period_id' => $periodId, 'competency_id' => (string) $entry['competency_id']], 'id', 1)[0] ?? null;
                $now = $this->clock->now();
                $scale = isset($entry['scale_code']) ? (string) $entry['scale_code'] : null;
                $absence = isset($entry['absence_code']) ? (string) $entry['absence_code'] : null;
                $remark = isset($entry['remark']) ? (string) $entry['remark'] : null;
                if ($existing === null) {
                    if (isset($entry['version']) && (int) $entry['version'] > 0) {
                        $conflicts[] = ['person_id' => (string) $entry['person_id'], 'competency_id' => (string) $entry['competency_id'], 'server_version' => null];
                        continue;
                    }
                    $id = (string) new Ulid();
                    $this->store->insert(self::MARKS, $t, $establishmentId, [
                        'id' => $id, 'person_id' => (string) $entry['person_id'], 'class_structure_id' => $classId, 'period_id' => $periodId,
                        'competency_id' => (string) $entry['competency_id'], 'scale_code' => $scale, 'absence_code' => $absence, 'remark' => $remark,
                        'status' => 'draft', 'entered_by' => $principal->userId, 'client_uuid' => $uuid, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->event($t, $establishmentId, $id, null, $scale ?? $absence, $principal->userId, null, $uuid, $now);
                    ++$saved;
                    $touched[] = $id;
                    continue;
                }
                $sent = isset($entry['version']) ? (int) $entry['version'] : null;
                if ($sent !== (int) $existing['version']) {
                    $conflicts[] = ['person_id' => (string) $entry['person_id'], 'competency_id' => (string) $entry['competency_id'], 'server_version' => self::markResource($existing)];
                    continue;
                }
                if ((string) $existing['scale_code'] === (string) $scale && (string) $existing['absence_code'] === (string) $absence && (string) $existing['remark'] === (string) $remark) {
                    ++$unchanged;
                    continue;
                }
                $this->store->update(self::MARKS, $t, $establishmentId, [
                    'scale_code' => $scale, 'absence_code' => $absence, 'remark' => $remark, 'version' => (int) $existing['version'] + 1,
                    'entered_by' => $principal->userId, 'updated_at' => $now,
                ], ['id' => (string) $existing['id']]);
                $this->event($t, $establishmentId, (string) $existing['id'], $existing['scale_code'] === null ? ($existing['absence_code'] === null ? null : (string) $existing['absence_code']) : (string) $existing['scale_code'], $scale ?? $absence, $principal->userId, null, $uuid, $now);
                ++$saved;
                $touched[] = (string) $existing['id'];
            }
            if ($conflicts !== []) {
                $fields = [];
                foreach ($conflicts as $c) {
                    $version = $c['server_version'] === null ? 'absente' : 'v' . $c['server_version']['version'];
                    $fields['entries.' . $c['person_id'] . '.' . $c['competency_id']] = ['Version périmée : l\'évaluation a changé sur le serveur (' . $version . '). Rechargez la feuille puis reprenez votre saisie.'];
                }

                throw new HttpException(409, 'Conflit', 'Version périmée : aucune évaluation du lot n\'a été enregistrée.', 'https://api.scholaris.cm/errors/conflict', $fields);
            }
        });

        $marks = [];
        foreach ($touched as $id) {
            $marks[] = self::markResource($this->store->findOrFail(self::MARKS, $t, $establishmentId, $id));
        }

        return ['saved' => $saved, 'duplicates' => $duplicates, 'unchanged' => $unchanged, 'marks' => $marks];
    }

    /** @return array<string, mixed> */
    public function lock(Principal $principal, string $establishmentId, string $classId, string $periodId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::VALIDATE);
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'pri_competency_sheet', $classId);
        $t = $principal->tenantId;
        $this->classes->resolve($t, $establishmentId, $classId);
        $state = $this->state($t, $establishmentId, $classId, $periodId);
        if ($state !== null && $state['status'] !== 'open') {
            throw HttpException::conflict('La feuille est déjà verrouillée.');
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($t, $establishmentId, $classId, $periodId, $state, $principal, $now): void {
            if ($state === null) {
                $this->store->insert(self::STATES, $t, $establishmentId, [
                    'id' => (string) new Ulid(), 'class_structure_id' => $classId, 'period_id' => $periodId, 'status' => 'locked',
                    'locked_by' => $principal->userId, 'locked_at' => $now, 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $this->store->update(self::STATES, $t, $establishmentId, ['status' => 'locked', 'locked_by' => $principal->userId, 'locked_at' => $now, 'updated_at' => $now], ['id' => (string) $state['id']]);
            }
            foreach ($this->store->select(self::MARKS, $t, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $periodId, 'status' => 'draft'], 'id', 20000) as $mark) {
                $this->store->update(self::MARKS, $t, $establishmentId, ['status' => 'locked', 'updated_at' => $now], ['id' => (string) $mark['id']]);
            }
        });
        $this->access->audit($principal, 'primary.competency_sheet.locked', 'pri_competency_sheet', $classId . ':' . $periodId, null, ['status' => 'locked']);

        return ['class_structure_id' => $classId, 'period_id' => $periodId, 'status' => 'locked', 'published' => false];
    }

    /**
     * Publie la feuille verrouillée aux familles (portail) : seules les feuilles publiées sont visibles des parents.
     *
     * @return array<string, mixed>
     */
    public function publish(Principal $principal, string $establishmentId, string $classId, string $periodId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::VALIDATE);
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'pri_competency_sheet', $classId);
        $t = $principal->tenantId;
        $state = $this->state($t, $establishmentId, $classId, $periodId);
        if ($state === null || $state['status'] === 'open') {
            throw HttpException::conflict('Verrouillez la feuille avant de la publier.');
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($t, $establishmentId, $classId, $periodId, $state, $now): void {
            $this->store->update(self::STATES, $t, $establishmentId, ['status' => 'published', 'published_at' => $state['published_at'] ?? $now, 'updated_at' => $now], ['id' => (string) $state['id']]);
            foreach ($this->store->select(self::MARKS, $t, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $periodId], 'id', 20000) as $mark) {
                $this->store->update(self::MARKS, $t, $establishmentId, ['status' => 'published', 'updated_at' => $now], ['id' => (string) $mark['id']]);
            }
        });
        $this->access->audit($principal, 'primary.competency_sheet.published', 'pri_competency_sheet', $classId . ':' . $periodId, null, ['status' => 'published']);

        return ['class_structure_id' => $classId, 'period_id' => $periodId, 'status' => 'published', 'published' => true];
    }

    /**
     * Demande de correction d'une évaluation d'une feuille verrouillée (motif obligatoire) ; appliquée à l'approbation de la direction.
     *
     * @return array<string, mixed>
     */
    public function requestCorrection(Principal $principal, string $establishmentId, string $markId, string $newCode, string $reason): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::ENTER);
        $t = $principal->tenantId;
        $mark = $this->store->findOrFail(self::MARKS, $t, $establishmentId, $markId, 'Évaluation introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $mark['class_structure_id'], 'pri_competency_mark', $markId);
        if ($mark['status'] === 'draft') {
            throw HttpException::conflict('La feuille n\'est pas verrouillée : modifiez directement l\'évaluation.');
        }
        if (!in_array($newCode, $this->scales->codes($t, $establishmentId), true)) {
            throw HttpException::validation(['new_code' => ['Valeur possible : ' . implode(', ', $this->scales->codes($t, $establishmentId)) . '.']]);
        }
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => ['Le motif est obligatoire.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert(self::CORRECTIONS, $t, $establishmentId, [
            'id' => $id, 'mark_id' => $markId, 'class_structure_id' => (string) $mark['class_structure_id'], 'new_code' => $newCode, 'reason' => trim($reason),
            'status' => 'pending', 'requested_by' => $principal->userId, 'decided_by' => null, 'decided_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'primary.competency_correction.requested', 'pri_competency_correction', $id, null, ['mark_id' => $markId, 'new_code' => $newCode]);

        return self::correctionResource($this->store->findOrFail(self::CORRECTIONS, $t, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function corrections(Principal $principal, string $establishmentId, ?string $status): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, [...self::ENTER, ...self::APPROVE, ...self::VALIDATE]);
        $where = $status === null ? [] : ['status' => $status];
        $rows = $this->store->select(self::CORRECTIONS, $principal->tenantId, $establishmentId, $where, 'created_at', 500);

        return array_values(array_map(self::correctionResource(...), array_filter($rows, static fn (array $r): bool => $perimeter->coversClass((string) $r['class_structure_id']))));
    }

    /** @return array<string, mixed> */
    public function decide(Principal $principal, string $establishmentId, string $correctionId, bool $approve): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::APPROVE);
        $t = $principal->tenantId;
        $correction = $this->store->findOrFail(self::CORRECTIONS, $t, $establishmentId, $correctionId, 'Correction introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $correction['class_structure_id']);
        if ($correction['status'] !== 'pending') {
            throw HttpException::conflict('Cette correction est déjà traitée.');
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($t, $establishmentId, $correction, $correctionId, $principal, $approve, $now): void {
            if ($approve) {
                $mark = $this->store->findOrFail(self::MARKS, $t, $establishmentId, (string) $correction['mark_id']);
                $this->store->update(self::MARKS, $t, $establishmentId, ['scale_code' => (string) $correction['new_code'], 'absence_code' => null, 'version' => (int) $mark['version'] + 1, 'updated_at' => $now], ['id' => (string) $mark['id']]);
                $this->event($t, $establishmentId, (string) $mark['id'], $mark['scale_code'] === null ? null : (string) $mark['scale_code'], (string) $correction['new_code'], $principal->userId, (string) $correction['reason'], null, $now);
            }
            $this->store->update(self::CORRECTIONS, $t, $establishmentId, ['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $principal->userId, 'decided_at' => $now, 'updated_at' => $now], ['id' => $correctionId]);
        });
        $this->access->audit($principal, $approve ? 'primary.competency_correction.approved' : 'primary.competency_correction.rejected', 'pri_competency_correction', $correctionId, null, ['mark_id' => (string) $correction['mark_id']]);

        return self::correctionResource($this->store->findOrFail(self::CORRECTIONS, $t, $establishmentId, $correctionId));
    }

    /** @return list<array<string, mixed>> */
    public function history(Principal $principal, string $establishmentId, string $markId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $t = $principal->tenantId;
        $mark = $this->store->findOrFail(self::MARKS, $t, $establishmentId, $markId, 'Évaluation introuvable.');
        $this->access->assertReadableClass($perimeter, (string) $mark['class_structure_id']);

        return array_map(static fn (array $e): array => [
            'id' => (string) $e['id'], 'previous_code' => $e['previous_code'] === null ? null : (string) $e['previous_code'],
            'new_code' => $e['new_code'] === null ? null : (string) $e['new_code'], 'actor_id' => (string) $e['actor_id'],
            'reason' => $e['reason'] === null ? null : (string) $e['reason'], 'occurred_at' => (string) $e['occurred_at'],
        ], $this->store->select(self::EVENTS, $t, $establishmentId, ['mark_id' => $markId], 'occurred_at, id', 200));
    }

    /** @return array<string, mixed>|null */
    private function state(string $tenantId, string $establishmentId, string $classId, string $periodId): ?array
    {
        return $this->store->select(self::STATES, $tenantId, $establishmentId, ['class_structure_id' => $classId, 'period_id' => $periodId], 'id', 1)[0] ?? null;
    }

    private function event(string $tenantId, string $establishmentId, string $markId, ?string $previous, ?string $new, string $actor, ?string $reason, ?string $uuid, string $now): void
    {
        $this->store->insert(self::EVENTS, $tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'mark_id' => $markId, 'previous_code' => $previous, 'new_code' => $new, 'actor_id' => $actor, 'reason' => $reason,
            'client_uuid' => $uuid, 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function markResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'competency_id' => (string) $row['competency_id'],
            'scale_code' => $row['scale_code'] === null ? null : (string) $row['scale_code'], 'absence_code' => $row['absence_code'] === null ? null : (string) $row['absence_code'],
            'remark' => $row['remark'] === null ? null : (string) $row['remark'], 'status' => (string) $row['status'], 'version' => (int) $row['version'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function correctionResource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'mark_id' => (string) $row['mark_id'], 'class_structure_id' => (string) $row['class_structure_id'], 'new_code' => (string) $row['new_code'],
            'reason' => (string) $row['reason'], 'status' => (string) $row['status'], 'requested_by' => (string) $row['requested_by'],
            'decided_by' => $row['decided_by'] === null ? null : (string) $row['decided_by'], 'decided_at' => $row['decided_at'] === null ? null : (string) $row['decided_at'],
        ];
    }
}
