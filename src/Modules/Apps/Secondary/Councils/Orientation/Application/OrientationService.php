<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application;

use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\SchoolRules;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/** Vœux, avis du conseiller, décisions du conseil et recours d'orientation (EX-SEC-50, PAS-02 à PAS-04). */
final class OrientationService
{
    private const READ = ['secondary.orientation.manage'];
    private const MANAGE = ['secondary.orientation.manage'];
    private const COUNCIL = ['secondary.council.preside'];
    private const LIVE_ENROLMENTS = [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SchoolRules $rules,
        private readonly EnrollmentDirectory $enrolments,
        private readonly PersonDirectory $people,
        private readonly OrgUnitTree $tree,
        private readonly UserPersonLink $userPeople,
        private readonly SecondaryClock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function index(Principal $principal, string $establishmentId, string $classId, string $academicYearId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->readableClass($principal, $establishmentId, $classId, $perimeter);
        $rows = [];
        foreach (array_unique($this->enrolments->personIdsInStructure($principal->tenantId, $classId)) as $personId) {
            if ($this->enrolment($principal->tenantId, $establishmentId, $personId, $academicYearId, $classId) === null) {
                continue;
            }
            $person = $this->people->find($principal->tenantId, $personId);
            if ($person === null || $person->tenantId !== $principal->tenantId) {
                continue;
            }
            $case = $this->caseRow($principal->tenantId, $establishmentId, $personId, $academicYearId);
            $rows[] = $this->describeCase($case, $personId, $person->fullName(), $classId, $academicYearId)
                + ['latest_decision' => $this->latestDecision($principal->tenantId, $establishmentId, $personId, $academicYearId)];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['student_name'], (string) $b['student_name']));

        return $rows;
    }

    /** @return array<string, mixed> */
    public function show(Principal $principal, string $establishmentId, string $personId, string $academicYearId): array
    {
        $classId = $this->classForRead($principal, $establishmentId, $personId, $academicYearId);
        $person = $this->people->find($principal->tenantId, $personId);
        if ($person === null || $person->tenantId !== $principal->tenantId) {
            throw HttpException::notFound('Élève introuvable.');
        }
        $case = $this->caseRow($principal->tenantId, $establishmentId, $personId, $academicYearId);

        return $this->describeCase($case, $personId, $person->fullName(), $classId, $academicYearId)
            + ['latest_decision' => $this->latestDecision($principal->tenantId, $establishmentId, $personId, $academicYearId)];
    }

    /** @return array<string, mixed> */
    public function showOwn(Principal $principal, string $establishmentId, string $academicYearId): array
    {
        $personId = $this->userPeople->personIdForUser($principal->tenantId, $principal->userId);
        if ($personId === null || $this->access->perimeter($principal, $establishmentId, ['secondary.self.read'])->isEmpty()) {
            throw HttpException::notFound('Élève introuvable pour ce compte.');
        }

        return $this->show($principal, $establishmentId, $personId, $academicYearId);
    }

    /**

     * @param list<string> $wishes

     * @return array<string, mixed>

     */
    public function saveOwnWishes(Principal $principal, string $establishmentId, string $academicYearId, array $wishes): array
    {
        $personId = $this->userPeople->personIdForUser($principal->tenantId, $principal->userId);
        if ($personId === null) {
            throw HttpException::notFound('Élève introuvable pour ce compte.');
        }
        $classId = $this->classForWrite($principal, $establishmentId, $personId, $academicYearId, self::MANAGE, true);
        foreach ($wishes as $streamId) {
            $this->activeStream($principal->tenantId, $establishmentId, $streamId);
        }
        $case = $this->saveCase($principal, $establishmentId, $personId, $academicYearId, $classId, [
            'wishes' => json_encode(array_values(array_unique($wishes)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $this->describeCase($case, $personId, $this->studentName($principal->tenantId, $personId), $classId, $academicYearId);
    }

    /** @return list<array<string, mixed>> */
    public function familyCases(Principal $principal, string $establishmentId, string $academicYearId): array
    {
        $guardianId = $this->userPeople->personIdForUser($principal->tenantId, $principal->userId);
        if ($guardianId === null || $this->access->perimeter($principal, $establishmentId, ['secondary.family.read'])->isEmpty()) {
            $this->access->require($principal, $establishmentId, ['secondary.family.read']);
        }
        $rows = [];
        foreach ($this->people->learnersOf($principal->tenantId, (string) $guardianId) as $learner) {
            if ($learner->tenantId !== $principal->tenantId) {
                continue;
            }
            $enrolment = $this->enrolment($principal->tenantId, $establishmentId, $learner->id, $academicYearId);
            if ($enrolment === null || $enrolment->structureId === null) {
                continue;
            }
            $case = $this->caseRow($principal->tenantId, $establishmentId, $learner->id, $academicYearId);
            $rows[] = $this->describeCase($case, $learner->id, $learner->fullName(), $enrolment->structureId, $academicYearId)
                + ['latest_decision' => $this->latestDecision($principal->tenantId, $establishmentId, $learner->id, $academicYearId)];
        }

        return $rows;
    }

    /**

     * @param list<string> $wishes

     * @return array<string, mixed>

     */
    public function saveWishes(Principal $principal, string $establishmentId, string $personId, string $academicYearId, array $wishes): array
    {
        $classId = $this->classForWrite($principal, $establishmentId, $personId, $academicYearId, self::MANAGE);
        foreach ($wishes as $streamId) {
            $this->activeStream($principal->tenantId, $establishmentId, $streamId);
        }
        $case = $this->saveCase($principal, $establishmentId, $personId, $academicYearId, $classId, [
            'wishes' => json_encode(array_values(array_unique($wishes)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $this->describeCase($case, $personId, $this->studentName($principal->tenantId, $personId), $classId, $academicYearId);
    }

    /**
     * @param array<string, scalar|null> $changes
     * @return array<string, mixed>
     */
    public function saveAdvice(Principal $principal, string $establishmentId, string $personId, string $academicYearId, array $changes): array
    {
        $classId = $this->classForWrite($principal, $establishmentId, $personId, $academicYearId, self::MANAGE);
        if (isset($changes['proposed_stream_id']) && is_string($changes['proposed_stream_id'])) {
            $this->activeStream($principal->tenantId, $establishmentId, $changes['proposed_stream_id']);
        }
        if ($changes === []) {
            throw HttpException::validation(['advice' => ['Saisissez un avis, une proposition ou une date d’entretien.']]);
        }
        $case = $this->saveCase($principal, $establishmentId, $personId, $academicYearId, $classId, $changes);

        return $this->describeCase($case, $personId, $this->studentName($principal->tenantId, $personId), $classId, $academicYearId);
    }

    /** @return array<string, mixed> */
    public function decide(Principal $principal, string $establishmentId, string $personId, string $academicYearId, string $streamId, string $reason): array
    {
        $classId = $this->classForWrite($principal, $establishmentId, $personId, $academicYearId, self::COUNCIL);
        $this->activeStream($principal->tenantId, $establishmentId, $streamId);
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->transaction(function () use ($principal, $establishmentId, $personId, $academicYearId, $classId, $streamId, $reason, $id, $now): void {
            $case = $this->saveCase($principal, $establishmentId, $personId, $academicYearId, $classId, []);
            $existing = $this->store->select('se_orientation_decisions', $principal->tenantId, $establishmentId, ['case_id' => (string) $case['id']], 'created_at DESC', 1)[0] ?? null;
            if ($existing !== null) {
                throw HttpException::conflict('Une décision d’orientation existe déjà pour cette année.');
            }
            $this->store->insert('se_orientation_decisions', $principal->tenantId, $establishmentId, [
                'id' => $id, 'case_id' => (string) $case['id'], 'person_id' => $personId, 'class_id' => $classId,
                'academic_year_id' => $academicYearId, 'stream_id' => $streamId, 'reason' => trim($reason),
                'decision_on' => $this->clock->today(), 'decided_by' => $principal->userId, 'created_at' => $now,
            ]);
            $this->store->update('se_orientations', $principal->tenantId, $establishmentId, [
                'council_opinion' => trim($reason), 'final_stream_id' => $streamId, 'updated_at' => $now,
            ], ['id' => (string) $case['id']]);
        });
        $this->access->audit($principal, 'secondary.orientation.decided', 'se_orientation_decision', $id, null, ['person_id' => $personId, 'class_id' => $classId, 'stream_id' => $streamId]);

        return self::decision($this->store->findOrFail('se_orientation_decisions', $principal->tenantId, $establishmentId, $id));
    }

    /** @return array<string, mixed> */
    public function appeal(Principal $principal, string $establishmentId, string $personId, string $academicYearId, string $reason): array
    {
        $classId = $this->classForAppeal($principal, $establishmentId, $personId, $academicYearId);
        if (trim($reason) === '' || mb_strlen(trim($reason)) > 255) {
            throw HttpException::validation(['reason' => ['Motif obligatoire (255 caractères au plus).']]);
        }
        $case = $this->caseRow($principal->tenantId, $establishmentId, $personId, $academicYearId);
        if ($case === null) {
            throw HttpException::conflict('Aucune décision d’orientation n’est disponible pour un recours.');
        }
        $decision = $this->store->select('se_orientation_decisions', $principal->tenantId, $establishmentId, ['case_id' => (string) $case['id']], 'created_at DESC', 1)[0] ?? null;
        if ($decision === null || (string) $decision['class_id'] !== $classId) {
            throw HttpException::conflict('Aucune décision d’orientation n’est disponible pour un recours.');
        }
        if ($this->store->count('se_orientation_appeals', $principal->tenantId, $establishmentId, ['decision_id' => (string) $decision['id']]) > 0) {
            throw HttpException::conflict('Un recours a déjà été déposé pour cette décision.');
        }
        $days = $this->rules->value($principal->tenantId, $establishmentId, 'appeal_days');
        if ((!is_int($days) && !(is_float($days) && floor($days) === $days)) || $days < 0) {
            throw HttpException::conflict('Le délai de recours doit être explicitement paramétré dans le règlement intérieur (secondary.appeal_days).');
        }
        $days = (int) $days;
        $deadline = (new DateTimeImmutable((string) $decision['decision_on']))->modify('+' . $days . ' days')->format('Y-m-d');
        if ($this->clock->today() > $deadline) {
            throw HttpException::validation(['appeal' => ['Le délai de recours est expiré.']]);
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->store->insert('se_orientation_appeals', $principal->tenantId, $establishmentId, [
            'id' => $id, 'decision_id' => (string) $decision['id'], 'case_id' => (string) $case['id'],
            'person_id' => $personId, 'class_id' => $classId, 'submitted_on' => $this->clock->today(), 'reason' => trim($reason),
            'status' => 'pending', 'outcome_reason' => null, 'revised_stream_id' => null, 'decided_by' => null, 'decided_at' => null, 'created_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.orientation.appeal_submitted', 'se_orientation_appeal', $id, null, ['decision_id' => (string) $decision['id']]);

        return self::appealRow($this->store->findOrFail('se_orientation_appeals', $principal->tenantId, $establishmentId, $id));
    }

    /** @return list<array<string, mixed>> */
    public function appeals(Principal $principal, string $establishmentId, string $classId): array
    {
        $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        $this->readableClass($principal, $establishmentId, $classId, $perimeter);

        return array_map(self::appealRow(...), $this->store->select('se_orientation_appeals', $principal->tenantId, $establishmentId, ['class_id' => $classId], 'submitted_on DESC, created_at DESC', 500));
    }

    /** @return list<array<string, mixed>> */
    public function ownAppeals(Principal $principal, string $establishmentId, string $academicYearId): array
    {
        $personId = $this->userPeople->personIdForUser($principal->tenantId, $principal->userId);
        $self = $personId !== null && !$this->access->perimeter($principal, $establishmentId, ['secondary.self.read'])->isEmpty();
        $family = $personId !== null && !$this->access->perimeter($principal, $establishmentId, ['secondary.family.read'])->isEmpty();
        if (!$self && !$family) {
            $this->access->require($principal, $establishmentId, self::READ);
        }
        $allowedIds = [];
        if ($self) {
            $allowedIds[] = $personId;
        } elseif ($family) {
            foreach ($this->people->learnersOf($principal->tenantId, $personId) as $learner) {
                if ($learner->tenantId === $principal->tenantId) {
                    $allowedIds[] = $learner->id;
                }
            }
        }
        $appeals = [];
        foreach (array_unique($allowedIds) as $allowedId) {
            $case = $this->caseRow($principal->tenantId, $establishmentId, $allowedId, $academicYearId);
            if ($case === null) {
                continue;
            }
            foreach ($this->store->select('se_orientation_appeals', $principal->tenantId, $establishmentId, ['case_id' => (string) $case['id']], 'submitted_on DESC, created_at DESC', 100) as $row) {
                $appeals[] = self::appealRow($row);
            }
        }

        return $appeals;
    }

    /** @return array<string, mixed> */
    public function decideAppeal(Principal $principal, string $establishmentId, string $appealId, string $outcome, string $reason, ?string $revisedStreamId): array
    {
        if (trim($reason) === '' || mb_strlen(trim($reason)) > 255) {
            throw HttpException::validation(['reason' => ['Motif obligatoire (255 caractères au plus).']]);
        }
        $appeal = $this->store->findOrFail('se_orientation_appeals', $principal->tenantId, $establishmentId, $appealId, 'Recours introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, self::COUNCIL);
        $this->access->assertWritableClass($principal, $perimeter, (string) $appeal['class_id'], 'se_orientation_appeal', $appealId);
        if ((string) $appeal['status'] !== 'pending') {
            throw HttpException::conflict('Ce recours a déjà été examiné.');
        }
        if ($revisedStreamId !== null) {
            $this->activeStream($principal->tenantId, $establishmentId, $revisedStreamId);
        }
        if ($outcome === 'accepted' && $revisedStreamId === null) {
            throw HttpException::validation(['revised_stream_id' => ['Une série ou spécialité révisée est obligatoire si le recours est accepté.']]);
        }
        $now = $this->clock->now();
        $this->store->transaction(function () use ($principal, $establishmentId, $appealId, $appeal, $outcome, $reason, $revisedStreamId, $now): void {
            $updated = $this->store->update('se_orientation_appeals', $principal->tenantId, $establishmentId, [
                'status' => $outcome, 'outcome_reason' => trim($reason), 'revised_stream_id' => $revisedStreamId,
                'decided_by' => $principal->userId, 'decided_at' => $now,
            ], ['id' => $appealId, 'status' => 'pending']);
            if ($updated !== 1) {
                throw HttpException::conflict('Ce recours a déjà été examiné.');
            }
            if ($outcome === 'accepted') {
                $this->store->update('se_orientations', $principal->tenantId, $establishmentId, [
                    'council_opinion' => trim($reason), 'final_stream_id' => $revisedStreamId, 'updated_at' => $now,
                ], ['id' => (string) $appeal['case_id']]);
            }
        });
        $this->access->audit($principal, 'secondary.orientation.appeal_decided', 'se_orientation_appeal', $appealId, $appeal, ['status' => $outcome, 'reason' => trim($reason)]);

        return self::appealRow($this->store->findOrFail('se_orientation_appeals', $principal->tenantId, $establishmentId, $appealId));
    }

    private function classForRead(Principal $principal, string $establishmentId, string $personId, string $academicYearId): string
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, self::READ);
        $linkedPersonId = $this->userPeople->personIdForUser($principal->tenantId, $principal->userId);
        $personalAccess = $linkedPersonId === $personId && !$this->access->perimeter($principal, $establishmentId, ['secondary.self.read'])->isEmpty();
        $familyAccess = $linkedPersonId !== null && !$this->access->perimeter($principal, $establishmentId, ['secondary.family.read'])->isEmpty()
            && $this->isLearnerOf($principal->tenantId, $linkedPersonId, $personId);
        if ($perimeter->isEmpty() && !$personalAccess && !$familyAccess) {
            $perimeter = $this->access->require($principal, $establishmentId, self::READ);
        }
        $enrolment = $this->enrolment($principal->tenantId, $establishmentId, $personId, $academicYearId);
        if ($enrolment === null || $enrolment->structureId === null) {
            throw HttpException::notFound('Élève introuvable dans cet établissement et cette année.');
        }
        if (!$personalAccess && !$familyAccess) {
            $this->readableClass($principal, $establishmentId, $enrolment->structureId, $perimeter);
        }

        return $enrolment->structureId;
    }

    /** @param list<string> $permissions */
    private function classForWrite(Principal $principal, string $establishmentId, string $personId, string $academicYearId, array $permissions, bool $allowSelf = false): string
    {
        $self = $allowSelf && $this->userPeople->personIdForUser($principal->tenantId, $principal->userId) === $personId;
        $perimeter = $this->access->perimeter($principal, $establishmentId, $permissions);
        $selfBypass = $self && $perimeter->isEmpty();
        if ($selfBypass) {
            $this->access->require($principal, $establishmentId, ['secondary.self.read']);
        } elseif ($perimeter->isEmpty()) {
            $perimeter = $this->access->require($principal, $establishmentId, $permissions);
        }
        $enrolment = $this->enrolment($principal->tenantId, $establishmentId, $personId, $academicYearId);
        if ($enrolment === null || $enrolment->structureId === null) {
            throw HttpException::notFound('Élève introuvable dans cet établissement et cette année.');
        }
        if (!$selfBypass) {
            $this->access->assertWritableClass($principal, $perimeter, $enrolment->structureId, 'se_orientation_case', $personId);
        }

        return $enrolment->structureId;
    }

    private function classForAppeal(Principal $principal, string $establishmentId, string $personId, string $academicYearId): string
    {
        $perimeter = $this->access->perimeter($principal, $establishmentId, self::MANAGE);
        $enrolment = $this->enrolment($principal->tenantId, $establishmentId, $personId, $academicYearId);
        if ($enrolment === null || $enrolment->structureId === null) {
            throw HttpException::notFound('Élève introuvable dans cet établissement et cette année.');
        }
        if (!$perimeter->isEmpty()) {
            $this->access->assertWritableClass($principal, $perimeter, $enrolment->structureId, 'se_orientation_case', $personId);

            return $enrolment->structureId;
        }

        $linkedPersonId = $this->userPeople->personIdForUser($principal->tenantId, $principal->userId);
        $family = $this->access->perimeter($principal, $establishmentId, ['secondary.family.read']);
        $self = $linkedPersonId === $personId && !$this->access->perimeter($principal, $establishmentId, ['secondary.self.read'])->isEmpty();
        $isChild = $linkedPersonId !== null && !$family->isEmpty() && $this->isLearnerOf($principal->tenantId, $linkedPersonId, $personId);
        if (!$isChild && !$self) {
            $this->access->require($principal, $establishmentId, self::MANAGE);
        }

        return $enrolment->structureId;
    }

    private function isLearnerOf(string $tenantId, string $guardianId, string $personId): bool
    {
        foreach ($this->people->learnersOf($tenantId, $guardianId) as $learner) {
            if ($learner->tenantId === $tenantId && $learner->id === $personId) {
                return true;
            }
        }

        return false;
    }

    private function readableClass(Principal $principal, string $establishmentId, string $classId, Perimeter $perimeter): void
    {
        $this->access->assertReadableClass($perimeter, $classId);
        $structure = $this->tree->structure($principal->tenantId, $establishmentId, $classId);
        if ($structure === null || $structure->establishmentId !== $establishmentId || $structure->kind !== 'class') {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }
    }

    private function enrolment(string $tenantId, string $establishmentId, string $personId, string $academicYearId, ?string $classId = null): ?EnrollmentInfo
    {
        foreach ($this->enrolments->forPerson($tenantId, $personId) as $enrolment) {
            if ($enrolment->tenantId === $tenantId && $enrolment->establishmentId === $establishmentId
                && $enrolment->academicYearId === $academicYearId && in_array($enrolment->status, self::LIVE_ENROLMENTS, true)
                && $enrolment->structureId !== null && ($classId === null || $enrolment->structureId === $classId)) {
                $structure = $this->tree->structure($tenantId, $establishmentId, $enrolment->structureId);
                if ($structure === null || $structure->establishmentId !== $establishmentId || $structure->kind !== 'class') {
                    continue;
                }
                return $enrolment;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function caseRow(string $tenantId, string $establishmentId, string $personId, string $academicYearId): ?array
    {
        return $this->store->select('se_orientations', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $academicYearId], 'created_at DESC', 1)[0] ?? null;
    }

    /**

     * @param array<string, scalar|null> $changes

     * @return array<string, mixed>

     */
    private function saveCase(Principal $principal, string $establishmentId, string $personId, string $academicYearId, string $classId, array $changes): array
    {
        $existing = $this->caseRow($principal->tenantId, $establishmentId, $personId, $academicYearId);
        $now = $this->clock->now();
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->store->insert('se_orientations', $principal->tenantId, $establishmentId, [
                'id' => $id, 'person_id' => $personId, 'academic_year_id' => $academicYearId,
                'wishes' => null, 'counsellor_opinion' => null, 'council_opinion' => null, 'proposed_stream_id' => null,
                'final_stream_id' => null, 'interview_on' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $existing = $this->store->findOrFail('se_orientations', $principal->tenantId, $establishmentId, $id);
        }
        if ($changes !== []) {
            $this->store->update('se_orientations', $principal->tenantId, $establishmentId, $changes + ['updated_at' => $now], ['id' => (string) $existing['id']]);
            $existing = $this->store->findOrFail('se_orientations', $principal->tenantId, $establishmentId, (string) $existing['id']);
        }

        return $existing;
    }

    private function activeStream(string $tenantId, string $establishmentId, string $streamId): void
    {
        $stream = $this->store->find('se_streams', $tenantId, $establishmentId, $streamId);
        if ($stream === null || (int) $stream['active'] !== 1) {
            throw HttpException::validation(['stream_id' => ['La série ou spécialité n’est pas ouverte dans cet établissement.']]);
        }
    }

    private function studentName(string $tenantId, string $personId): string
    {
        $person = $this->people->find($tenantId, $personId);
        if ($person === null || $person->tenantId !== $tenantId) {
            throw HttpException::notFound('Élève introuvable.');
        }

        return $person->fullName();
    }

    /** @return array<string, mixed>|null */
    private function latestDecision(string $tenantId, string $establishmentId, string $personId, string $academicYearId): ?array
    {
        $row = $this->store->select('se_orientation_decisions', $tenantId, $establishmentId, ['person_id' => $personId, 'academic_year_id' => $academicYearId], 'created_at DESC', 1)[0] ?? null;

        return $row === null ? null : self::decision($row);
    }

    /**

     * @param array<string, mixed>|null $case

     * @return array<string, mixed>

     */
    private function describeCase(?array $case, string $personId, string $name, string $classId, string $academicYearId): array
    {
        $wishes = $case === null || !is_string($case['wishes'] ?? null) ? [] : json_decode((string) $case['wishes'], true);

        return [
            'id' => $case === null ? null : (string) $case['id'], 'person_id' => $personId, 'student_name' => $name,
            'class_id' => $classId, 'academic_year_id' => $academicYearId, 'wishes' => is_array($wishes) && array_is_list($wishes) ? $wishes : [],
            'counsellor_opinion' => $case['counsellor_opinion'] ?? null, 'proposed_stream_id' => $case['proposed_stream_id'] ?? null,
            'interview_on' => $case['interview_on'] ?? null,
        ];
    }

    /**

     * @param array<string, mixed> $row

     * @return array<string, mixed>

     */
    private static function decision(array $row): array
    {
        return ['id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'class_id' => (string) $row['class_id'],
            'academic_year_id' => (string) $row['academic_year_id'], 'stream_id' => (string) $row['stream_id'], 'reason' => (string) $row['reason'],
            'decision_on' => (string) $row['decision_on'], 'decided_by' => (string) $row['decided_by']];
    }

    /**

     * @param array<string, mixed> $row

     * @return array<string, mixed>

     */
    private static function appealRow(array $row): array
    {
        return ['id' => (string) $row['id'], 'decision_id' => (string) $row['decision_id'], 'person_id' => (string) $row['person_id'],
            'class_id' => (string) $row['class_id'], 'submitted_on' => (string) $row['submitted_on'], 'reason' => (string) $row['reason'],
            'status' => (string) $row['status'], 'outcome_reason' => $row['outcome_reason'], 'revised_stream_id' => $row['revised_stream_id'],
            'decided_by' => $row['decided_by'], 'decided_at' => $row['decided_at']];
    }
}
