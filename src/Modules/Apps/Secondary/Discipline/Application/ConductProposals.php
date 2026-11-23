<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use DomainException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Councils\Contracts\ConductRules;
use Scholaris\Modules\Apps\Secondary\Discipline\Domain\DisciplineDefaults as D;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Propositions de sanction du barème (T865, RG-PRE-1, RG-PRE-2, TST-10) : l'application PROPOSE à partir des heures d'absence non
 * justifiées de la période ; une personne habilitée selon le niveau du palier décide (appliquée, ou rejetée avec un motif) ; un
 * palier `conseil_discipline` ne s'applique que par le conseil (RG-PRE-3). Recalcul idempotent : une proposition existante du même
 * palier et de la même période n'est jamais recréée ; un palier supérieur remplace les propositions encore ouvertes.
 */
final class ConductProposals
{
    public const TABLE = 'se_conduct_proposals';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ConductRules $rules,
        private readonly DisciplineScale $scale,
        private readonly AttendanceHours $hours,
        private readonly ClassRoster $roster,
        private readonly DisciplineNotices $notices,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array{from: string, to: string, label: string} $period
     * @return array{period: array{from: string, to: string, label: string}, scale_version: string, examined: int, created: list<array<string, mixed>>, unchanged: int}
     */
    public function compute(Principal $principal, string $establishmentId, Perimeter $perimeter, ?string $classId, array $period): array
    {
        $tenantId = $principal->tenantId;
        if ($classId !== null) {
            $students = $this->roster->ofClass($tenantId, $establishmentId, $classId);
            $this->access->assertWritableClass($principal, $perimeter, $classId, 'conduct_proposal', $classId);
        } else {
            $this->access->assertWhole($principal, $perimeter, 'conduct_proposal');
            $students = $this->roster->ofEstablishment($tenantId, $establishmentId);
        }
        $current = $this->scale->current($tenantId, $establishmentId);
        if (!$current['configured']) {
            throw new HttpException(409, 'Barème non paramétré', $current['errors'] === []
                ? 'Le barème des heures d\'absence (règlement intérieur) n\'est pas encore paramétré pour cet établissement.'
                : 'Le barème paramétré est invalide : ' . implode(' ', $current['errors']));
        }
        $totals = $this->hours->byLearner($tenantId, $establishmentId, $period['from'], $period['to']);
        $created = [];
        $unchanged = 0;
        foreach ($students as $student) {
            $hours = AttendanceHours::hours($totals[$student['person_id']] ?? null)['unjustified_hours'];
            $repeat = $this->isRepeat($tenantId, $establishmentId, $student['person_id'], $period['from']);
            $proposal = $this->rules->propose($current['scale'], $student['person_id'], $hours, $repeat);
            if ($proposal === null) {
                continue;
            }
            $existing = $this->store->select(self::TABLE, $tenantId, $establishmentId, [
                'person_id' => $student['person_id'], 'period_from' => $period['from'], 'period_to' => $period['to'], 'proposed_kind' => $proposal['code'],
            ], 'id', 1)[0] ?? null;
            if ($existing !== null) {
                ++$unchanged;
                continue;
            }
            $created[] = $this->insert($principal, $establishmentId, $student, $period, $proposal, $current['version'], $repeat);
        }

        return ['period' => $period, 'scale_version' => $current['version'], 'examined' => count($students), 'created' => $created, 'unchanged' => $unchanged];
    }

    /** @return array<string, mixed> */
    public function decide(Principal $principal, string $establishmentId, Perimeter $perimeter, string $id, bool $apply, ?string $reason): array
    {
        $tenantId = $principal->tenantId;
        $row = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Proposition introuvable.');
        $this->access->assertReadableClass($perimeter, self::classKey($row));
        if ($row['status'] !== ConductRules::PROPOSED) {
            throw HttpException::conflict('Cette proposition a déjà fait l\'objet d\'une décision.');
        }
        $level = (string) $row['decision_level'];
        if ($apply && $level === D::COUNCIL_LEVEL) {
            throw HttpException::conflict('Ce palier relève du conseil de discipline : convoquez-le, sa décision s\'appliquera (RG-PRE-3).');
        }
        $allowed = D::LEVEL_PERMISSIONS[$level] ?? D::DEFAULT_LEVEL_PERMISSIONS;
        $this->access->require($principal, $establishmentId, $allowed);
        $this->access->assertWritableClass($principal, $this->access->perimeter($principal, $establishmentId, $allowed), self::classKey($row), 'conduct_proposal', $id);

        return $this->record($principal, $establishmentId, $row, $apply, $reason, null);
    }

    /**
     * Décision portée par un conseil de discipline (RG-PRE-3) : la proposition liée est appliquée, ou rejetée si le conseil ne
     * sanctionne pas. Sans effet si elle a déjà été décidée.
     */
    public function decideByCouncil(Principal $principal, string $establishmentId, string $id, bool $apply, string $reason, string $councilId): void
    {
        $row = $this->store->find(self::TABLE, $principal->tenantId, $establishmentId, $id);
        if ($row !== null && $row['status'] === ConductRules::PROPOSED) {
            $this->record($principal, $establishmentId, $row, $apply, $reason, $councilId);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'class_id' => $row['class_id'],
            'period_from' => (string) $row['period_from'], 'period_to' => (string) $row['period_to'], 'period_label' => (string) $row['period_label'],
            'unjustified_hours' => (float) $row['unjustified_hours'], 'proposed_kind' => (string) $row['proposed_kind'],
            'proposed_label' => (string) $row['proposed_label'], 'decision_level' => (string) $row['decision_level'],
            'scale_version' => (string) $row['scale_version'], 'repeat_offence' => (int) $row['repeat_offence'] === 1, 'status' => (string) $row['status'],
            'decided_by' => $row['decided_by'], 'decided_at' => $row['decided_at'], 'decision_reason' => $row['decision_reason'],
            'council_id' => $row['council_id'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function record(Principal $principal, string $establishmentId, array $row, bool $apply, ?string $reason, ?string $councilId): array
    {
        try {
            $decided = $this->rules->decide([
                'person_id' => (string) $row['person_id'], 'code' => (string) $row['proposed_kind'], 'label' => (string) $row['proposed_label'],
                'level' => (string) $row['decision_level'], 'hours' => (float) $row['unjustified_hours'], 'status' => (string) $row['status'],
            ], $apply, $principal->userId, $reason);
        } catch (DomainException $e) {
            throw HttpException::validation(['reason' => [$e->getMessage()]]);
        }
        $now = $this->clock->now();
        $values = ['status' => $decided['status'], 'decided_by' => $principal->userId, 'decided_at' => $now, 'decision_reason' => $decided['reason'] ?? ($reason === '' ? null : $reason), 'updated_at' => $now];
        if ($councilId !== null) {
            $values['council_id'] = $councilId;
        }
        $updated = $this->store->update(self::TABLE, $principal->tenantId, $establishmentId, $values, ['id' => (string) $row['id'], 'status' => ConductRules::PROPOSED]);
        if ($updated !== 1) {
            throw HttpException::conflict('Cette proposition a déjà fait l\'objet d\'une décision.');
        }
        $this->access->audit($principal, 'secondary.conduct.' . $decided['status'], 'conduct_proposal', (string) $row['id'], ['status' => ConductRules::PROPOSED], ['status' => $decided['status'], 'kind' => $row['proposed_kind'], 'council_id' => $councilId]);

        return self::resource($this->store->findOrFail(self::TABLE, $principal->tenantId, $establishmentId, (string) $row['id']));
    }

    /**
     * @param array{person_id: string, first_name: string, last_name: string, class_id: string} $student
     * @param array{from: string, to: string, label: string} $period
     * @param array{code: string, label: string, level: string, hours: float, status: string} $proposal
     * @return array<string, mixed>
     */
    private function insert(Principal $principal, string $establishmentId, array $student, array $period, array $proposal, string $version, bool $repeat): array
    {
        $tenantId = $principal->tenantId;
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($tenantId, $establishmentId, $student, $period, $proposal, $version, $repeat, $id, $now, $principal): void {
            foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $student['person_id'], 'period_from' => $period['from'], 'period_to' => $period['to'], 'status' => ConductRules::PROPOSED]) as $open) {
                $this->store->update(self::TABLE, $tenantId, $establishmentId, ['status' => D::SUPERSEDED, 'updated_at' => $now], ['id' => (string) $open['id'], 'status' => ConductRules::PROPOSED]);
            }
            $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
                'id' => $id, 'person_id' => $student['person_id'], 'class_id' => $student['class_id'], 'period_from' => $period['from'],
                'period_to' => $period['to'], 'period_label' => mb_substr($period['label'], 0, 80), 'unjustified_hours' => $proposal['hours'],
                'proposed_kind' => $proposal['code'], 'proposed_label' => mb_substr($proposal['label'], 0, 160), 'decision_level' => mb_substr($proposal['level'], 0, 40),
                'scale_version' => $version, 'repeat_offence' => $repeat ? 1 : 0, 'status' => ConductRules::PROPOSED, 'proposed_by' => $principal->userId,
                'decided_by' => null, 'decided_at' => null, 'decision_reason' => null, 'council_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->access->audit($principal, 'secondary.conduct.proposed', 'conduct_proposal', $id, null, ['person_id' => $student['person_id'], 'kind' => $proposal['code'], 'hours' => $proposal['hours']]);
        });
        $className = $this->roster->className($tenantId, $establishmentId, $student['class_id']);
        $this->notices->absenceThreshold($tenantId, $establishmentId, $id, $student['person_id'], $className, $proposal['hours'], $period['label']);

        return self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /** Récidive : une sanction du barème a déjà été appliquée à l'élève pour une période antérieure. */
    private function isRepeat(string $tenantId, string $establishmentId, string $personId, string $from): bool
    {
        foreach ($this->store->select(self::TABLE, $tenantId, $establishmentId, ['person_id' => $personId, 'status' => ConductRules::APPLIED]) as $row) {
            if ((string) $row['period_to'] < $from) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private static function classKey(array $row): ?string
    {
        return is_string($row['class_id'] ?? null) ? $row['class_id'] : null;
    }
}
