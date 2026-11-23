<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Attendance\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Higher\Attendance\Documents\AttendanceDocumentTypes;
use Scholaris\Modules\Apps\Higher\Attendance\Infrastructure\AttendanceStore;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;

/**
 * Discipline de l'IPES (EX-PRE-04) : sanctions (avertissement, blâme, exclusion temporaire, exclusion définitive) et conseil de discipline
 * (convocation, séance et PV, signature avec empreinte SHA-256). RG-PRE-2 : l'exclusion définitive exige le PV signé du conseil et
 * clôt l'inscription au statut « exclu » (port EnrolmentExclusion). Notification de sanction DOC-31.
 */
final class DisciplineService
{
    public const COUNCILS = 'hi_discipline_councils';
    public const SANCTIONS = 'hi_sanctions';
    public const DECISIONS = ['none', 'warning', 'reprimand', 'temporary_exclusion', 'permanent_exclusion'];

    private const LABELS = [
        'warning' => 'Avertissement', 'reprimand' => 'Blâme', 'temporary_exclusion' => 'Exclusion temporaire', 'permanent_exclusion' => 'Exclusion définitive',
    ];

    public function __construct(
        private readonly AttendanceStore $store,
        private readonly AttendanceAccess $access,
        private readonly Placement $placement,
        private readonly EnrolmentExclusion $exclusion,
        private readonly HigherDocumentIssuer $documents,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * Sanction prononcée hors conseil (avertissement, blâme, exclusion temporaire). L'exclusion définitive passe par le conseil.
     *
     * @return array<string, mixed>
     */
    public function sanction(Principal $principal, string $establishmentId, string $personId, ?string $enrolmentId, string $kind, string $reason, ?string $startsOn, ?string $endsOn): array
    {
        if ($kind === 'permanent_exclusion') {
            throw HttpException::conflict('RG-PRE-2 : l\'exclusion définitive exige le PV signé du conseil de discipline.');
        }
        if ($kind === 'temporary_exclusion' && ($startsOn === null || $endsOn === null || $endsOn < $startsOn)) {
            throw HttpException::validation(['ends_on' => ['Une exclusion temporaire exige une période (début et fin).']]);
        }
        $tenantId = $principal->tenantId;
        [$enrolment, $place] = $this->placement->enrolment($tenantId, $establishmentId, $personId, $enrolmentId);
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::SANCTION), $place, 'hi_sanction', $personId);

        return $this->record($tenantId, $establishmentId, $place, $personId, $enrolment->id, $kind, $reason, $startsOn, $endsOn, null, $principal->userId);
    }

    /**
     * @param list<string> $members
     * @return array<string, mixed>
     */
    public function convene(Principal $principal, string $establishmentId, string $personId, ?string $enrolmentId, string $reason, ?string $incidentRef, string $hearingDate, array $members): array
    {
        $tenantId = $principal->tenantId;
        [$enrolment, $place] = $this->placement->enrolment($tenantId, $establishmentId, $personId, $enrolmentId);
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::COUNCIL), $place, 'hi_discipline_council', $personId);
        if (count($members) < 3) {
            throw HttpException::validation(['members' => ['Le conseil de discipline compte au moins trois membres.']]);
        }
        $now = $this->clock->now();
        $row = $place + [
            'id' => (string) new Ulid(), 'person_id' => $personId, 'enrolment_id' => $enrolment->id, 'reason' => $reason, 'incident_ref' => $incidentRef,
            'hearing_date' => $hearingDate, 'members' => json_encode($members, JSON_THROW_ON_ERROR), 'status' => 'convened',
            'enrolment_excluded' => 0, 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->store->insert(self::COUNCILS, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $principal->userId, 'higher.council.convened', 'hi_discipline_council', $row['id'], null, $row);

        return $this->store->findOrFail(self::COUNCILS, $tenantId, $establishmentId, $row['id']);
    }

    /**
     * Séance tenue : PV (texte) et décision ; modifiable tant que le PV n'est pas signé.
     *
     * @return array<string, mixed>
     */
    public function hearing(Principal $principal, string $establishmentId, string $id, string $minutes, string $decision, string $decisionReason, ?string $from, ?string $to): array
    {
        $tenantId = $principal->tenantId;
        $council = $this->writable($principal, $establishmentId, $id);
        if ($council['status'] === 'signed') {
            throw HttpException::conflict('PV signé : la décision du conseil est figée.');
        }
        if ($decision === 'temporary_exclusion' && ($from === null || $to === null || $to < $from)) {
            throw HttpException::validation(['exclusion_to' => ['Une exclusion temporaire exige une période (début et fin).']]);
        }
        $changes = [
            'minutes_text' => $minutes, 'decision' => $decision, 'decision_reason' => $decisionReason, 'exclusion_from' => $from, 'exclusion_to' => $to,
            'status' => 'held', 'updated_at' => $this->clock->now(),
        ];
        $this->store->update(self::COUNCILS, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.council.held', 'hi_discipline_council', $id, ['status' => $council['status']], $changes);

        return $this->store->findOrFail(self::COUNCILS, $tenantId, $establishmentId, $id);
    }

    /**
     * Signature du PV (empreinte SHA-256 du contenu) : la décision devient une sanction effective ; une exclusion définitive clôt
     * l'inscription au statut « exclu » (RG-PRE-2).
     *
     * @return array<string, mixed>
     */
    public function sign(Principal $principal, string $establishmentId, string $id): array
    {
        $tenantId = $principal->tenantId;
        $council = $this->writable($principal, $establishmentId, $id);
        if ($council['status'] !== 'held') {
            throw HttpException::conflict($council['status'] === 'signed' ? 'PV déjà signé.' : 'Consignez d\'abord la séance et la décision du conseil.');
        }
        $now = $this->clock->now();
        $hash = hash('sha256', implode('|', [$council['id'], $council['person_id'], $council['minutes_text'], $council['decision'], $council['decision_reason'], $council['members'], $now]));
        $sanctionId = null;
        $excluded = false;
        $decision = (string) $council['decision'];
        if ($decision !== 'none') {
            $place = array_intersect_key($council, ['school_id' => 1, 'department_id' => 1, 'program_id' => 1, 'level_id' => 1, 'group_id' => 1]);
            $starts = $decision === 'permanent_exclusion' ? $this->clock->today() : ($council['exclusion_from'] === null ? null : (string) $council['exclusion_from']);
            $sanctionId = (string) $this->record($tenantId, $establishmentId, $place, (string) $council['person_id'], (string) $council['enrolment_id'], $decision, (string) $council['decision_reason'], $starts, $council['exclusion_to'] === null ? null : (string) $council['exclusion_to'], $id, $principal->userId)['id'];
            if ($decision === 'permanent_exclusion') {
                $excluded = $this->exclusion->exclude($tenantId, $establishmentId, (string) $council['enrolment_id'], 'Exclusion définitive, conseil de discipline du ' . $council['hearing_date'], $id, $principal->userId);
            }
        }
        $changes = ['status' => 'signed', 'minutes_hash' => $hash, 'signed_by' => $principal->userId, 'signed_at' => $now, 'sanction_id' => $sanctionId, 'enrolment_excluded' => $excluded ? 1 : 0, 'updated_at' => $now];
        $this->store->update(self::COUNCILS, $tenantId, $establishmentId, $changes, ['id' => $id]);
        $this->access->audit($tenantId, $principal->userId, 'higher.council.signed', 'hi_discipline_council', $id, ['status' => 'held'], $changes);

        return $this->store->findOrFail(self::COUNCILS, $tenantId, $establishmentId, $id);
    }

    /**
     * Notification de la sanction (DOC-31), signée par le directeur d'école.
     *
     * @return array<string, mixed>
     */
    public function notify(Principal $principal, string $establishmentId, string $sanctionId): array
    {
        $tenantId = $principal->tenantId;
        $sanction = $this->store->findOrFail(self::SANCTIONS, $tenantId, $establishmentId, $sanctionId, 'Sanction introuvable.');
        $perimeter = $this->access->require($principal, $establishmentId, array_merge(AttendancePermissions::SANCTION, AttendancePermissions::COUNCIL));
        $this->access->assertWritable($principal, $perimeter, $sanction, 'hi_sanction', $sanctionId);
        if ($sanction['document_id'] !== null) {
            throw HttpException::conflict('Notification déjà émise (' . $sanction['document_number'] . ').');
        }
        $period = $sanction['starts_on'] === null ? '-' : $sanction['starts_on'] . ($sanction['ends_on'] === null ? '' : ' - ' . $sanction['ends_on']);
        $document = $this->documents->issueFor($tenantId, $establishmentId, AttendanceDocumentTypes::SANCTION_NOTICE, (string) $sanction['person_id'], (string) $sanction['school_id'], (string) $sanction['program_id'], (string) $sanction['level_id'], [
            'sanction_label' => self::LABELS[(string) $sanction['sanction_kind']] ?? (string) $sanction['sanction_kind'], 'sanction_reason' => (string) $sanction['reason'],
            'sanction_period' => $period, 'council_reference' => (string) ($sanction['council_id'] ?? '-'),
        ], $principal->userId);
        $this->store->update(self::SANCTIONS, $tenantId, $establishmentId, ['document_id' => (string) ($document['id'] ?? ''), 'document_number' => isset($document['number']) ? (string) $document['number'] : null], ['id' => $sanctionId]);
        $this->access->audit($tenantId, $principal->userId, 'higher.sanction.notified', 'hi_sanction', $sanctionId, null, ['document' => $document['number'] ?? null]);

        return $document;
    }

    /** @return array<string, mixed> */
    public function readableCouncil(Principal $principal, string $establishmentId, string $id): array
    {
        $council = $this->store->findOrFail(self::COUNCILS, $principal->tenantId, $establishmentId, $id, 'Conseil introuvable.');
        $this->access->assertReadable($this->access->require($principal, $establishmentId, AttendancePermissions::DISCIPLINE_READ), $council);

        return $council;
    }

    /**
     * @param array<string, mixed> $place
     * @return array<string, mixed>
     */
    private function record(string $tenantId, string $establishmentId, array $place, string $personId, string $enrolmentId, string $kind, string $reason, ?string $startsOn, ?string $endsOn, ?string $councilId, string $actorId): array
    {
        $row = [
            'id' => (string) new Ulid(), 'school_id' => $place['school_id'], 'department_id' => $place['department_id'] ?? null, 'program_id' => $place['program_id'],
            'level_id' => $place['level_id'], 'group_id' => $place['group_id'] ?? null, 'person_id' => $personId, 'enrolment_id' => $enrolmentId,
            'sanction_kind' => $kind, 'reason' => $reason, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'council_id' => $councilId, 'status' => 'effective',
            'decided_by' => $actorId, 'decided_at' => $this->clock->now(),
        ];
        $this->store->insert(self::SANCTIONS, $tenantId, $establishmentId, $row);
        $this->access->audit($tenantId, $actorId, 'higher.sanction.recorded', 'hi_sanction', $row['id'], null, $row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function writable(Principal $principal, string $establishmentId, string $id): array
    {
        $council = $this->store->findOrFail(self::COUNCILS, $principal->tenantId, $establishmentId, $id, 'Conseil introuvable.');
        $this->access->assertWritable($principal, $this->access->require($principal, $establishmentId, AttendancePermissions::COUNCIL), $council, 'hi_discipline_council', $id);

        return $council;
    }
}
