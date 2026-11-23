<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Discipline\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Discipline\Domain\DisciplineDefaults as D;
use Scholaris\Modules\Apps\Secondary\Enrolment\Contracts\StudentRegistry;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/**
 * Conseil de discipline (T866, EX-SEC-37, RG-PRE-3) : convocation (dossier, faits, membres ; NOT-06 aux garants), puis décision
 * en séance avec procès-verbal. Une exclusion définitive exige le procès-verbal et l'audition de l'élève ET de ses parents ; elle
 * est inscrite au dossier de l'élève (StudentRegistry, réinscription sur décision motivée, RG-INS-3). La proposition du barème
 * liée est appliquée (ou rejetée si le conseil ne sanctionne pas). Une seule décision.
 */
final class DisciplineCouncils
{
    public const TABLE = 'se_discipline_councils';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly ClassRoster $roster,
        private readonly ConductProposals $proposals,
        private readonly DisciplineNotices $notices,
        private readonly StudentRegistry $students,
        private readonly SecondaryClock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data person_id, class_id, proposal_id, facts, hearing_on, hearing_time, place, members
     * @return array<string, mixed>
     */
    public function convene(Principal $principal, string $establishmentId, Perimeter $perimeter, array $data): array
    {
        $tenantId = $principal->tenantId;
        $personId = (string) $data['person_id'];
        $classId = (string) $data['class_id'];
        $this->roster->assertInClass($tenantId, $establishmentId, $personId, $classId);
        $id = (string) new Ulid();
        $this->access->assertWritableClass($principal, $perimeter, $classId, 'discipline_council', $id);
        $proposalId = is_string($data['proposal_id'] ?? null) ? $data['proposal_id'] : null;
        if ($proposalId !== null) {
            $proposal = $this->store->findOrFail(ConductProposals::TABLE, $tenantId, $establishmentId, $proposalId, 'Proposition introuvable.');
            if ($proposal['person_id'] !== $personId) {
                throw HttpException::validation(['proposal_id' => ['Cette proposition concerne un autre élève.']]);
            }
        }
        if ((string) $data['hearing_on'] < $this->clock->today()) {
            throw HttpException::validation(['hearing_on' => ['La séance ne peut pas être datée dans le passé.']]);
        }
        $now = $this->clock->now();
        $this->store->insert(self::TABLE, $tenantId, $establishmentId, [
            'id' => $id, 'person_id' => $personId, 'class_id' => $classId, 'proposal_id' => $proposalId, 'facts' => (string) $data['facts'],
            'convened_on' => $this->clock->today(), 'hearing_on' => (string) $data['hearing_on'], 'hearing_time' => (string) $data['hearing_time'],
            'place' => (string) $data['place'], 'members' => (string) json_encode(array_values((array) ($data['members'] ?? [])), JSON_UNESCAPED_UNICODE),
            'status' => D::CONVENED, 'decision_kind' => null, 'decision_reason' => null, 'minutes_text' => null, 'student_heard' => 0, 'parents_heard' => 0,
            'exclusion_days' => null, 'convened_by' => $principal->userId, 'decided_by' => null, 'decided_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.discipline_council.convened', 'discipline_council', $id, null, ['person_id' => $personId, 'proposal_id' => $proposalId]);
        $this->notices->councilConvened($tenantId, $establishmentId, $id, $personId, (string) $data['hearing_on'], (string) $data['hearing_time'], (string) $data['place']);

        return self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $data decision_kind, decision_reason, minutes, student_heard, parents_heard, exclusion_days
     * @return array<string, mixed>
     */
    public function decide(Principal $principal, string $establishmentId, Perimeter $perimeter, string $id, array $data): array
    {
        $tenantId = $principal->tenantId;
        $council = $this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id, 'Conseil de discipline introuvable.');
        $this->access->assertWritableClass($principal, $perimeter, (string) $council['class_id'], 'discipline_council', $id);
        if ($council['status'] !== D::CONVENED) {
            throw HttpException::conflict('Ce conseil de discipline a déjà rendu sa décision.');
        }
        $kind = (string) $data['decision_kind'];
        $minutes = trim((string) ($data['minutes'] ?? ''));
        $studentHeard = ($data['student_heard'] ?? false) === true;
        $parentsHeard = ($data['parents_heard'] ?? false) === true;
        if ($kind === D::FINAL_EXCLUSION) {
            $errors = [];
            if ($minutes === '') {
                $errors['minutes'] = ['Une exclusion définitive exige le procès-verbal du conseil (RG-PRE-3).'];
            }
            if (!$studentHeard) {
                $errors['student_heard'] = ['L\'élève doit avoir été entendu (RG-PRE-3).'];
            }
            if (!$parentsHeard) {
                $errors['parents_heard'] = ['Les parents doivent avoir été entendus (RG-PRE-3).'];
            }
            if ($errors !== []) {
                throw HttpException::validation($errors);
            }
        }
        $now = $this->clock->now();
        $reason = (string) $data['decision_reason'];
        $this->store->transaction(function () use ($principal, $establishmentId, $tenantId, $id, $council, $kind, $minutes, $studentHeard, $parentsHeard, $data, $now, $reason): void {
            $updated = $this->store->update(self::TABLE, $tenantId, $establishmentId, [
                'status' => D::DECIDED, 'decision_kind' => $kind, 'decision_reason' => $reason, 'minutes_text' => $minutes === '' ? null : $minutes,
                'student_heard' => $studentHeard ? 1 : 0, 'parents_heard' => $parentsHeard ? 1 : 0,
                'exclusion_days' => is_int($data['exclusion_days'] ?? null) ? $data['exclusion_days'] : null,
                'decided_by' => $principal->userId, 'decided_at' => $now, 'updated_at' => $now,
            ], ['id' => $id, 'status' => D::CONVENED]);
            if ($updated !== 1) {
                throw HttpException::conflict('Ce conseil de discipline a déjà rendu sa décision.');
            }
            if (is_string($council['proposal_id'])) {
                $this->proposals->decideByCouncil($principal, $establishmentId, $council['proposal_id'], $kind !== D::NO_SANCTION, $reason, $id);
            }
            if ($kind === D::FINAL_EXCLUSION) {
                $this->students->markFinalExclusion($tenantId, $establishmentId, (string) $council['person_id'], $this->clock->today(), 'conseil-discipline:' . $id);
            }
            $this->access->audit($principal, 'secondary.discipline_council.decided', 'discipline_council', $id, ['status' => D::CONVENED], ['decision_kind' => $kind, 'person_id' => $council['person_id']]);
        });

        return self::resource($this->store->findOrFail(self::TABLE, $tenantId, $establishmentId, $id));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function resource(array $row): array
    {
        $members = json_decode((string) $row['members'], true);

        return [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'class_id' => (string) $row['class_id'],
            'proposal_id' => $row['proposal_id'], 'facts' => (string) $row['facts'], 'convened_on' => (string) $row['convened_on'],
            'hearing_on' => (string) $row['hearing_on'], 'hearing_time' => (string) $row['hearing_time'], 'place' => (string) $row['place'],
            'members' => is_array($members) ? array_values($members) : [], 'status' => (string) $row['status'],
            'decision_kind' => $row['decision_kind'], 'decision_reason' => $row['decision_reason'], 'minutes' => $row['minutes_text'],
            'student_heard' => (int) $row['student_heard'] === 1, 'parents_heard' => (int) $row['parents_heard'] === 1,
            'exclusion_days' => $row['exclusion_days'] === null ? null : (int) $row['exclusion_days'],
            'convened_by' => (string) $row['convened_by'], 'decided_by' => $row['decided_by'], 'decided_at' => $row['decided_at'],
        ];
    }
}
