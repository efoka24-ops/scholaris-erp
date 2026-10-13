<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Application;

use PDOException;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Attendance\Infrastructure\AttendanceRepository;
use Scholaris\Modules\Socle\Attendance\Infrastructure\Db;
use Scholaris\Modules\Socle\Attendance\Infrastructure\JustificationRepository;
use Scholaris\Modules\Socle\Audit\Contracts\AuditLog;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\Scheduling\Contracts\SlotInfo;
use Scholaris\Modules\Socle\Scheduling\Contracts\TimetableDirectory;

/**
 * Appel par séance, rattaché à un créneau de l'emploi du temps. Idempotent par client_uuid (séance et ligne) pour la
 * saisie hors ligne : un rejeu ne crée jamais de doublon, un conflit est signalé en 409 avec les deux versions.
 */
final class SessionService
{
    public function __construct(
        private readonly Db $db,
        private readonly AttendanceRepository $repository,
        private readonly JustificationRepository $justifications,
        private readonly TimetableDirectory $timetable,
        private readonly EnrollmentDirectory $enrollments,
        private readonly AttendanceAccess $access,
        private readonly AttendanceSettings $settings,
        private readonly AbsenceNotifier $notifier,
        private readonly TaughtHoursReporter $hours,
        private readonly AuditLog $audit,
        private readonly AttendanceClock $clock,
    ) {
    }

    /**
     * Crée la séance d'appel d'un créneau pour un jour ; un rejeu du même client_uuid renvoie la séance existante.
     *
     * @return array{0: array<string, mixed>, 1: bool} séance et indicateur de création
     */
    public function open(Principal $principal, string $establishmentId, string $slotId, string $date, string $clientUuid): array
    {
        $tenantId = $principal->tenantId;
        $clientVersion = ['client_uuid' => $clientUuid, 'slot_id' => $slotId, 'date' => $date];

        $existing = $this->repository->sessionByClient($tenantId, $establishmentId, $clientUuid);
        if ($existing !== null) {
            if ($existing['slot_id'] !== $slotId || $existing['session_date'] !== $date) {
                throw new SessionConflict('Ce client_uuid identifie déjà un autre appel. Générez un nouvel identifiant sur l\'appareil.', $this->detail($existing), $clientVersion);
            }

            return [$this->detail($existing), false];
        }

        if ($date > $this->clock->today()) {
            throw HttpException::validation(['date' => ['L\'appel ne peut pas être fait pour un jour à venir.']]);
        }
        $held = $this->heldSlot($tenantId, $establishmentId, $slotId, $date);
        $actor = $this->access->personIdOf($principal);
        if ($actor === null || $held->teacherPersonId !== $actor) {
            throw HttpException::forbidden('Seul l\'enseignant du créneau, ou son remplaçant désigné, peut faire l\'appel.');
        }

        $sameSlot = $this->repository->sessionBySlotDay($tenantId, $slotId, $date);
        if ($sameSlot !== null) {
            throw new SessionConflict('Un appel existe déjà pour ce créneau et ce jour. Rejoignez-le ou attendez sa validation.', $this->detail($sameSlot), $clientVersion);
        }

        $now = $this->clock->now();
        $id = (string) new Ulid();
        try {
            $this->repository->insert('attendance_sessions', [
                'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'slot_id' => $slotId,
                'structure_id' => $held->structureId, 'course_unit_id' => $held->courseUnitId,
                'teacher_person_id' => $held->teacherPersonId, 'subject_label' => $held->subjectLabel,
                'session_date' => $date, 'start_time' => $held->startTime, 'end_time' => $held->endTime, 'status' => 'open',
                'client_uuid' => $clientUuid, 'expected_count' => count($this->expected($tenantId, $held->structureId)),
                'taken_by' => $principal->userId, 'validated_by' => null, 'validated_at' => null, 'synced_at' => null,
                'qr_token_hash' => null, 'qr_expires_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if (!$this->db->isUniqueViolation($e)) {
                throw $e;
            }
            $raced = $this->repository->sessionByClient($tenantId, $establishmentId, $clientUuid);
            if ($raced !== null && $raced['slot_id'] === $slotId && $raced['session_date'] === $date) {
                return [$this->detail($raced), false];
            }
            throw new SessionConflict('Un appel existe déjà pour ce créneau et ce jour.', $this->detail($this->repository->sessionBySlotDay($tenantId, $slotId, $date) ?? []), $clientVersion);
        }

        return [$this->detail($this->mustFind($tenantId, $establishmentId, $id)), true];
    }

    /**
     * Enregistre les lignes par lot. Sur une séance ouverte, la dernière saisie fait foi ; sur une séance validée, un rejeu
     * identique est sans effet et toute différence est un conflit (409, deux versions).
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function saveRecords(Principal $principal, string $establishmentId, string $sessionId, array $lines): array
    {
        $tenantId = $principal->tenantId;
        $session = $this->mustFind($tenantId, $establishmentId, $sessionId);
        $this->authorizeTaker($principal, $session);
        $expected = $this->expected($tenantId, (string) $session['structure_id']);

        $errors = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            $person = (string) $line['person_id'];
            if (!in_array($person, $expected, true)) {
                $errors["records.$index.person_id"] = ['Cet élève n\'est pas attendu à cet appel.'];
            } elseif (isset($seen[$person])) {
                $errors["records.$index.person_id"] = ['Cet élève figure deux fois dans la requête.'];
            }
            $seen[$person] = true;
            if (isset($line['client_uuid'])) {
                $other = $this->db->one('SELECT person_id FROM attendance_records WHERE tenant_id = ? AND session_id = ? AND client_uuid = ?', [$tenantId, $sessionId, $line['client_uuid']]);
                if ($other !== null && $other['person_id'] !== $person) {
                    $errors["records.$index.client_uuid"] = ['Ce client_uuid est déjà utilisé pour un autre élève de cet appel.'];
                }
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $existing = [];
        foreach ($this->repository->records($tenantId, $sessionId) as $row) {
            $existing[(string) $row['person_id']] = $row;
        }

        if ($session['status'] === 'validated') {
            $this->assertNoConflict($session, $existing, $lines);

            return $this->detail($session);
        }

        $now = $this->clock->now();
        $this->db->transaction(function () use ($tenantId, $establishmentId, $session, $sessionId, $lines, $existing, $now): void {
            foreach ($lines as $line) {
                $person = (string) $line['person_id'];
                $values = [
                    'status' => $line['status'], 'arrived_at' => $line['arrived_at'] ?? null,
                    'client_uuid' => $line['client_uuid'] ?? ($existing[$person]['client_uuid'] ?? null), 'note' => $line['note'] ?? null, 'updated_at' => $now,
                ];
                if (isset($existing[$person])) {
                    $this->repository->updateRecord($tenantId, (string) $existing[$person]['id'], $values);
                    continue;
                }
                $this->repository->insert('attendance_records', $values + [
                    'id' => (string) new Ulid(), 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'session_id' => $sessionId,
                    'person_id' => $person, 'session_date' => $session['session_date'], 'justified' => 0, 'justification_id' => null, 'created_at' => $now,
                ]);
            }
            $this->repository->updateSession($tenantId, $sessionId, ['updated_at' => $now]);
        });

        return $this->detail($this->mustFind($tenantId, $establishmentId, $sessionId));
    }

    /**
     * Valide l'appel : chaque élève attendu doit avoir un statut. Les parents des absents non justifiés sont prévenus (une fois par jour).
     *
     * @return array<string, mixed>
     */
    public function validate(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $tenantId = $principal->tenantId;
        $session = $this->mustFind($tenantId, $establishmentId, $sessionId);
        $this->authorizeTaker($principal, $session);
        if ($session['status'] === 'validated') {
            return $this->detail($session);
        }

        $recorded = [];
        foreach ($this->repository->records($tenantId, $sessionId) as $row) {
            $recorded[(string) $row['person_id']] = true;
        }
        $missing = array_values(array_filter($this->expected($tenantId, (string) $session['structure_id']), static fn (string $id): bool => !isset($recorded[$id])));
        if ($missing !== []) {
            throw HttpException::validation(
                ['records' => [sprintf('Il manque le statut de %d élève(s) sur %d. Renseignez-les avant de valider l\'appel.', count($missing), (int) $session['expected_count'])], 'missing_person_ids' => $missing],
                'L\'appel est incomplet.',
            );
        }

        $now = $this->clock->now();
        $records = $this->db->transaction(function () use ($tenantId, $establishmentId, $sessionId, $principal, $now): array {
            $this->repository->updateSession($tenantId, $sessionId, [
                'status' => 'validated', 'validated_by' => $principal->userId, 'validated_at' => $now, 'synced_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($this->repository->records($tenantId, $sessionId) as $row) {
                if ($row['status'] === 'A' && (int) $row['justified'] === 0) {
                    $justificationId = $this->justifications->acceptedCovering($tenantId, $establishmentId, (string) $row['person_id'], (string) $row['session_date']);
                    if ($justificationId !== null) {
                        $this->repository->updateRecord($tenantId, (string) $row['id'], ['justified' => 1, 'justification_id' => $justificationId, 'updated_at' => $now]);
                    }
                }
            }
            $this->audit->append($tenantId, $principal->userId, 'attendance.session.validated', 'attendance_session', $sessionId, ['status' => 'open'], ['status' => 'validated']);

            return $this->repository->records($tenantId, $sessionId);
        });

        $validated = $this->mustFind($tenantId, $establishmentId, $sessionId);
        $this->notifier->notify($validated, $records);
        $this->hours->report($validated);

        return $this->detail($validated);
    }

    /**
     * Un appel complet en un envoi : création (idempotente), lignes, puis validation si demandée.
     *
     * @param array<string, mixed> $item client_uuid, slot_id, date, records, validate
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function process(Principal $principal, string $establishmentId, array $item): array
    {
        [$session, $created] = $this->open($principal, $establishmentId, (string) $item['slot_id'], (string) $item['date'], (string) $item['client_uuid']);
        $sessionId = (string) $session['id'];
        if (($item['records'] ?? []) !== []) {
            $session = $this->saveRecords($principal, $establishmentId, $sessionId, $item['records']);
        }
        if (($item['validate'] ?? false) === true) {
            $session = $this->validate($principal, $establishmentId, $sessionId);
        }

        return [$session, $created];
    }

    /**
     * Synchronisation par lot de l'appel enregistré hors ligne. Chaque appel est traité indépendamment et rejouable.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function sync(Principal $principal, string $establishmentId, array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $clientUuid = (string) $item['client_uuid'];
            try {
                [$session, $created] = $this->process($principal, $establishmentId, $item);
                $sessionId = (string) $session['id'];
                $this->repository->updateSession($principal->tenantId, $sessionId, ['synced_at' => $this->clock->now()]);
                $results[] = ['client_uuid' => $clientUuid, 'status' => 'synced', 'replayed' => !$created, 'session' => $this->detail($this->mustFind($principal->tenantId, $establishmentId, $sessionId))];
            } catch (SessionConflict $e) {
                $results[] = ['client_uuid' => $clientUuid, 'status' => 'conflict', 'problem' => $e->toProblem()];
            } catch (HttpException $e) {
                $results[] = ['client_uuid' => $clientUuid, 'status' => 'error', 'problem' => [
                    'status' => $e->status, 'title' => $e->title, 'detail' => $e->getMessage(), 'errors' => $e->errors,
                ]];
            }
        }

        return $results;
    }

    /**
     * Jeton de séance à durée limitée, affiché en QR par l'enseignant (pointage par l'étudiant). Seule l'empreinte est conservée.
     *
     * @return array{token: string, expires_at: string}
     */
    public function issueToken(Principal $principal, string $establishmentId, string $sessionId): array
    {
        $session = $this->mustFind($principal->tenantId, $establishmentId, $sessionId);
        $this->authorizeTaker($principal, $session);
        if ($session['status'] !== 'open') {
            throw HttpException::conflict('L\'appel est déjà validé : aucun code ne peut plus être émis.');
        }
        $token = bin2hex(random_bytes(16));
        $expires = $this->clock->plusMinutes($this->settings->qrWindowMinutes($principal->tenantId, $establishmentId));
        $this->repository->updateSession($principal->tenantId, $sessionId, ['qr_token_hash' => hash('sha256', $token), 'qr_expires_at' => $expires, 'updated_at' => $this->clock->now()]);

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * Pointage de l'étudiant : jeton valide, dans la plage courte, étudiant attendu à cet appel. Ne remplace jamais une saisie existante.
     *
     * @return array<string, mixed> ligne de présence
     */
    public function scan(Principal $principal, string $establishmentId, string $sessionId, string $token): array
    {
        $tenantId = $principal->tenantId;
        $session = $this->mustFind($tenantId, $establishmentId, $sessionId);
        $invalid = HttpException::forbidden('Code de séance invalide ou expiré. Demandez à l\'enseignant d\'afficher un nouveau code.');
        $hash = $session['qr_token_hash'];
        if (!is_string($hash) || !hash_equals($hash, hash('sha256', $token)) || strtotime((string) $session['qr_expires_at']) < strtotime($this->clock->now())) {
            throw $invalid;
        }
        $learner = $this->access->personIdOf($principal);
        if ($learner === null || !in_array($learner, $this->expected($tenantId, (string) $session['structure_id']), true)) {
            throw HttpException::forbidden('Vous n\'êtes pas attendu à cet appel.');
        }
        if ($session['status'] !== 'open') {
            throw HttpException::conflict('L\'appel est déjà validé.');
        }

        foreach ($this->repository->records($tenantId, $sessionId) as $row) {
            if ($row['person_id'] === $learner) {
                return $row;
            }
        }
        $now = $this->clock->now();
        $id = (string) new Ulid();
        $this->repository->insert('attendance_records', [
            'id' => $id, 'tenant_id' => $tenantId, 'establishment_id' => $establishmentId, 'session_id' => $sessionId, 'person_id' => $learner,
            'session_date' => $session['session_date'], 'status' => 'P', 'arrived_at' => null, 'client_uuid' => null, 'justified' => 0,
            'justification_id' => null, 'note' => 'Pointage par code de séance', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->db->one('SELECT * FROM attendance_records WHERE tenant_id = ? AND id = ?', [$tenantId, $id]) ?? [];
    }

    /**
     * Séance et compteurs (« 39 présents · 2 absents · 1 retard »).
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function detail(array $session): array
    {
        if ($session === []) {
            return [];
        }
        $records = $this->repository->records((string) $session['tenant_id'], (string) $session['id']);
        $counts = ['P' => 0, 'A' => 0, 'R' => 0];
        foreach ($records as $record) {
            $counts[(string) $record['status']] = ($counts[(string) $record['status']] ?? 0) + 1;
        }

        return $session + [
            'present_count' => $counts['P'], 'absent_count' => $counts['A'], 'late_count' => $counts['R'],
            'recorded_count' => count($records), 'records' => $records,
        ];
    }

    /** @return array<string, mixed> */
    public function find(string $tenantId, string $establishmentId, string $sessionId): array
    {
        return $this->detail($this->mustFind($tenantId, $establishmentId, $sessionId));
    }

    /** @return array<string, mixed> */
    private function mustFind(string $tenantId, string $establishmentId, string $sessionId): array
    {
        return $this->repository->session($tenantId, $establishmentId, $sessionId) ?? throw HttpException::notFound('Appel introuvable.');
    }

    /** Créneau tenu ce jour-là (modifications ponctuelles et remplacements appliqués), du client et de l'établissement. */
    private function heldSlot(string $tenantId, string $establishmentId, string $slotId, string $date): SlotInfo
    {
        $slot = $this->timetable->slot($tenantId, $slotId);
        if ($slot === null || $slot->tenantId !== $tenantId || $slot->establishmentId !== $establishmentId) {
            throw HttpException::notFound('Créneau introuvable.');
        }
        foreach ($this->timetable->slotsOn($tenantId, $establishmentId, $date) as $held) {
            if ($held->id === $slotId) {
                return $held;
            }
        }
        throw HttpException::validation(['slot_id' => ['Ce créneau n\'est pas tenu ce jour-là (annulé ou hors période). Vérifiez la date.']]);
    }

    /** @return list<string> élèves attendus (inscriptions actives de la classe) */
    private function expected(string $tenantId, string $structureId): array
    {
        return array_values(array_unique($this->enrollments->personIdsInStructure($tenantId, $structureId)));
    }

    /** @param array<string, mixed> $session */
    private function authorizeTaker(Principal $principal, array $session): void
    {
        if ($session['taken_by'] === $principal->userId || $this->access->personIdOf($principal) === $session['teacher_person_id']) {
            return;
        }
        throw HttpException::forbidden('Seul l\'enseignant de cet appel peut le modifier.');
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, array<string, mixed>> $existing
     * @param list<array<string, mixed>> $lines
     */
    private function assertNoConflict(array $session, array $existing, array $lines): void
    {
        $diffs = [];
        foreach ($lines as $line) {
            $row = $existing[(string) $line['person_id']] ?? null;
            $same = $row !== null && $row['status'] === $line['status'] && ($row['arrived_at'] ?? null) === ($line['arrived_at'] ?? null);
            if (!$same) {
                $diffs[] = $line;
            }
        }
        if ($diffs !== []) {
            throw new SessionConflict(
                'Cet appel est déjà validé avec un contenu différent. Comparez les deux versions : la correction passe par une demande motivée.',
                $this->detail($session),
                ['client_uuid' => $session['client_uuid'], 'records' => $diffs],
            );
        }
    }
}
