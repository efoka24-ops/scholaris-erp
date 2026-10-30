<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use DateTimeImmutable;
use PDO;
use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\PublicSchools\Contracts\ClassRoster;
use Scholaris\Modules\Apps\PublicSchools\Contracts\PupilTransfer;
use Scholaris\Modules\Apps\PublicSchools\Contracts\SchoolRegistry;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentCatalog;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;
use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\LearnerNumbering;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Transferts d'élèves entre écoles publiques (EX-ELV-05, RG-ELV-4, RG-ELV-5, DOC-03, API-03) : demande de l'école d'origine, accord
 * de l'école d'accueil, clôture de l'inscription d'origine à la date d'accueil, inscription dans la classe d'accueil, certificat de
 * radiation (exeat) et dossier utile. L'élève n'est inscrit que dans une école à la fois : aucune inscription n'est créée avant l'accord.
 */
final class TransferService implements PupilTransfer
{
    private const LIVE = ['active', 'conditional', 'suspended'];
    private const DEFAULT_EXEAT_TYPE = 'public_exeat';
    private const DEFAULT_REPORT_TYPES = ['report_card'];

    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly SchoolRegistry $schools,
        private readonly ClassRoster $classes,
        private readonly EnrollmentDirectory $enrollments,
        private readonly LearnerWriter $writer,
        private readonly LearnerNumbering $numbering,
        private readonly PersonDirectory $people,
        private readonly DocumentIssuer $documents,
        private readonly DocumentCatalog $catalog,
        private readonly SettingsReader $settings,
        private readonly PupilClock $clock,
    ) {
    }

    public function request(string $tenantId, string $personId, string $fromEstablishmentId, string $toEstablishmentId, string $actorId): string
    {
        if ($fromEstablishmentId === $toEstablishmentId) {
            throw new \InvalidArgumentException('L\'école d\'accueil doit être différente de l\'école d\'origine.');
        }
        if (!$this->schools->isOpen($tenantId, $toEstablishmentId)) {
            throw new \InvalidArgumentException('L\'école d\'accueil est fermée, suspendue ou inconnue.');
        }
        if ($this->liveEnrollment($tenantId, $fromEstablishmentId, $personId) === null) {
            throw new \InvalidArgumentException('L\'élève n\'est pas inscrit dans l\'école d\'origine.');
        }
        $pending = $this->connections->pdo()->prepare(
            'SELECT 1 FROM pub_transfers WHERE tenant_id = ? AND person_id = ? AND state IN (?, ?) LIMIT 1',
        );
        $pending->execute([$tenantId, $personId, self::REQUESTED, self::ACCEPTED]);
        if ($pending->fetchColumn() !== false) {
            throw HttpException::conflict('Un transfert est déjà en cours pour cet élève.');
        }
        $id = (string) new Ulid();
        $this->connections->pdo()->prepare(
            'INSERT INTO pub_transfers (id, tenant_id, person_id, from_establishment_id, to_establishment_id, state, requested_by, requested_at, decided_by, decided_at, arrival_on, refusal_reason, exeat_document_id, to_structure_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL)',
        )->execute([$id, $tenantId, $personId, $fromEstablishmentId, $toEstablishmentId, self::REQUESTED, $actorId, $this->clock->now()]);

        return $id;
    }

    public function accept(string $tenantId, string $transferId, string $arrivalOn, string $actorId): void
    {
        $this->acceptInto($tenantId, $transferId, $arrivalOn, null, $actorId);
    }

    /**
     * Accord de l'école d'accueil : clôt l'inscription d'origine à la date d'accueil puis, si une classe d'accueil est donnée, inscrit l'élève
     * (état « completed ») ; sans classe, le transfert reste « accepted » jusqu'à `complete`. Rend le certificat de radiation émis, s'il l'est.
     *
     * @return array{exeat_document_id: ?string}
     *
     * @throws \InvalidArgumentException transfert plus à l'état « requested », date ou classe invalide
     */
    public function acceptInto(string $tenantId, string $transferId, string $arrivalOn, ?string $structureId, string $actorId): array
    {
        self::assertDate($arrivalOn);
        $row = $this->require($tenantId, $transferId);
        if ($row['state'] !== self::REQUESTED) {
            throw new \InvalidArgumentException('Le transfert n\'est plus à l\'état « requested ».');
        }
        if ($structureId !== null && $this->classes->find($tenantId, (string) $row['to_establishment_id'], $structureId) === null) {
            throw new \InvalidArgumentException('La classe d\'accueil n\'appartient pas à l\'école d\'accueil.');
        }
        $live = $this->liveEnrollment($tenantId, (string) $row['from_establishment_id'], (string) $row['person_id'])
            ?? throw new \InvalidArgumentException('L\'élève n\'est plus inscrit dans l\'école d\'origine.');

        $claim = $this->connections->pdo()->prepare(
            'UPDATE pub_transfers SET state = ?, decided_by = ?, decided_at = ?, arrival_on = ? WHERE tenant_id = ? AND id = ? AND state = ?',
        );
        $claim->execute([self::ACCEPTED, $actorId, $this->clock->now(), $arrivalOn, $tenantId, $transferId, self::REQUESTED]);
        if ($claim->rowCount() !== 1) {
            throw new \InvalidArgumentException('Le transfert n\'est plus à l\'état « requested ».');
        }
        try {
            $this->writer->closeEnrollment($tenantId, $live['id'], 'transferred', $arrivalOn);
        } catch (\Throwable $failure) {
            $this->connections->pdo()->prepare(
                'UPDATE pub_transfers SET state = ?, decided_by = NULL, decided_at = NULL, arrival_on = NULL WHERE tenant_id = ? AND id = ?',
            )->execute([self::REQUESTED, $tenantId, $transferId]);

            throw $failure;
        }
        $exeat = $this->issueExeat($tenantId, $transferId, $actorId);
        if ($structureId !== null) {
            $this->complete($tenantId, $transferId, $structureId, $actorId);
        }

        return ['exeat_document_id' => $exeat];
    }

    /**
     * Inscription de l'élève dans la classe d'accueil après l'accord (état « accepted » vers « completed »).
     *
     * @throws \InvalidArgumentException transfert pas accepté ou classe invalide
     */
    public function complete(string $tenantId, string $transferId, string $structureId, string $actorId): void
    {
        $row = $this->require($tenantId, $transferId);
        if ($row['state'] !== self::ACCEPTED) {
            throw new \InvalidArgumentException('Le transfert doit être accepté avant l\'inscription dans l\'école d\'accueil.');
        }
        $to = (string) $row['to_establishment_id'];
        if ($this->classes->find($tenantId, $to, $structureId) === null) {
            throw new \InvalidArgumentException('La classe d\'accueil n\'appartient pas à l\'école d\'accueil.');
        }
        $year = $this->connections->pdo()->prepare('SELECT academic_year_id FROM pub_class_profiles WHERE tenant_id = ? AND establishment_id = ? AND structure_id = ?');
        $year->execute([$tenantId, $to, $structureId]);
        $yearId = (string) $year->fetchColumn();
        $this->writer->enroll($tenantId, $to, (string) $row['person_id'], $structureId, $yearId, (string) $row['arrival_on'], $actorId);
        $this->connections->pdo()->prepare('UPDATE pub_transfers SET state = ?, to_structure_id = ? WHERE tenant_id = ? AND id = ? AND state = ?')
            ->execute([self::COMPLETED, $structureId, $tenantId, $transferId, self::ACCEPTED]);
    }

    public function refuse(string $tenantId, string $transferId, string $reason, string $actorId): void
    {
        if (trim($reason) === '' || mb_strlen(trim($reason)) > 500) {
            throw new \InvalidArgumentException('Un motif de refus de 500 caractères au plus est obligatoire.');
        }
        $this->require($tenantId, $transferId);
        $update = $this->connections->pdo()->prepare(
            'UPDATE pub_transfers SET state = ?, decided_by = ?, decided_at = ?, refusal_reason = ? WHERE tenant_id = ? AND id = ? AND state = ?',
        );
        $update->execute([self::REFUSED, $actorId, $this->clock->now(), trim($reason), $tenantId, $transferId, self::REQUESTED]);
        if ($update->rowCount() !== 1) {
            throw new \InvalidArgumentException('Le transfert n\'est plus à l\'état « requested ».');
        }
    }

    public function stateOf(string $tenantId, string $transferId): ?string
    {
        return ($this->find($tenantId, $transferId))['state'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId, string $transferId): ?array
    {
        $query = $this->connections->pdo()->prepare('SELECT * FROM pub_transfers WHERE tenant_id = ? AND id = ?');
        $query->execute([$tenantId, $transferId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Transferts qui touchent l'école (sortants, entrants ou les deux), du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(string $tenantId, string $establishmentId, ?string $direction): array
    {
        $sql = 'SELECT * FROM pub_transfers WHERE tenant_id = ? AND ';
        $params = [$tenantId];
        if ($direction === 'incoming') {
            $sql .= 'to_establishment_id = ?';
            $params[] = $establishmentId;
        } elseif ($direction === 'outgoing') {
            $sql .= 'from_establishment_id = ?';
            $params[] = $establishmentId;
        } else {
            $sql .= '(from_establishment_id = ? OR to_establishment_id = ?)';
            array_push($params, $establishmentId, $establishmentId);
        }
        $query = $this->connections->pdo()->prepare($sql . ' ORDER BY requested_at DESC, id DESC');
        $query->execute($params);

        return array_values(array_map(fn (array $row): array => $this->present($tenantId, $row), $query->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    /**
     * Certificat de radiation (DOC-03) de l'école d'origine ; null tant que le modèle de document n'est pas disponible (réessai possible).
     */
    public function issueExeat(string $tenantId, string $transferId, string $actorId): ?string
    {
        $row = $this->require($tenantId, $transferId);
        if (!in_array($row['state'], [self::ACCEPTED, self::COMPLETED], true)) {
            throw new \InvalidArgumentException('Le certificat de radiation n\'est délivré qu\'après l\'accord de l\'école d\'accueil.');
        }
        if ($row['exeat_document_id'] !== null) {
            return (string) $row['exeat_document_id'];
        }
        $type = $this->settings->value($tenantId, (string) $row['from_establishment_id'], 'public_schools.documents.exeat_type', self::DEFAULT_EXEAT_TYPE);
        try {
            $document = $this->documents->issue(
                $tenantId,
                (string) $row['from_establishment_id'],
                is_string($type) ? $type : self::DEFAULT_EXEAT_TYPE,
                (string) $row['person_id'],
                ['learner_number' => $this->numbering->find($tenantId, (string) $row['person_id']) ?? '', 'arrival_on' => (string) $row['arrival_on']],
                $actorId,
            );
        } catch (HttpException $unavailable) {
            if ($unavailable->status !== 422) {
                throw $unavailable;
            }

            return null;
        }
        $this->connections->pdo()->prepare('UPDATE pub_transfers SET exeat_document_id = ? WHERE tenant_id = ? AND id = ?')->execute([$document->id, $tenantId, $transferId]);

        return $document->id;
    }

    /**
     * Dossier utile transmis à l'école d'accueil après l'accord (RG-ELV-5) : identité, identifiant, parcours et dernier bulletin émis.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException transfert pas encore accepté
     */
    public function dossier(string $tenantId, string $transferId): array
    {
        $row = $this->require($tenantId, $transferId);
        if (!in_array($row['state'], [self::ACCEPTED, self::COMPLETED], true)) {
            throw new \InvalidArgumentException('Le dossier n\'est transmis qu\'après l\'accord de l\'école d\'accueil.');
        }
        $personId = (string) $row['person_id'];
        $person = $this->people->find($tenantId, $personId);
        $registration = $this->connections->pdo()->prepare('SELECT birth_date FROM pub_pupil_registrations WHERE tenant_id = ? AND person_id = ?');
        $registration->execute([$tenantId, $personId]);
        $birthDate = $registration->fetchColumn();
        $profile = $this->connections->pdo()->prepare('SELECT birth_act_ref, birth_act_verified FROM pub_pupil_profiles WHERE tenant_id = ? AND person_id = ?');
        $profile->execute([$tenantId, $personId]);
        $profileRow = $profile->fetch(PDO::FETCH_ASSOC);
        $types = $this->settings->value($tenantId, (string) $row['to_establishment_id'], 'public_schools.transfer.report_types', self::DEFAULT_REPORT_TYPES);
        $types = is_array($types) ? $types : self::DEFAULT_REPORT_TYPES;
        $report = null;
        foreach ($this->catalog->validFor($tenantId, $personId) as $document) {
            if (in_array($document->type, $types, true)) {
                $report = ['document_id' => $document->id, 'number' => $document->number, 'type' => $document->type];
                break;
            }
        }
        $schooling = [];
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            $schooling[] = [
                'establishment_id' => $enrollment->establishmentId,
                'academic_year_id' => $enrollment->academicYearId,
                'structure_id' => $enrollment->structureId,
                'status' => $enrollment->status,
            ];
        }

        return [
            'person_id' => $personId,
            'learner_number' => $this->numbering->find($tenantId, $personId),
            'last_name' => $person?->lastName,
            'first_name' => $person?->firstName,
            'birth_date' => $birthDate !== false ? (string) $birthDate : null,
            'birth_act_ref' => is_array($profileRow) ? $profileRow['birth_act_ref'] : null,
            'birth_act_verified' => is_array($profileRow) && (bool) $profileRow['birth_act_verified'],
            'schooling' => $schooling,
            'last_report' => $report,
        ];
    }

    /** @return array<string, mixed> */
    private function require(string $tenantId, string $transferId): array
    {
        return $this->find($tenantId, $transferId) ?? throw new \InvalidArgumentException('Transfert introuvable.');
    }

    /** @return array{id: string}|null */
    private function liveEnrollment(string $tenantId, string $establishmentId, string $personId): ?array
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && in_array($enrollment->status, self::LIVE, true)) {
                return ['id' => $enrollment->id];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(string $tenantId, array $row): array
    {
        $person = $this->people->find($tenantId, (string) $row['person_id']);

        return [
            'id' => (string) $row['id'],
            'person_id' => (string) $row['person_id'],
            'pupil_name' => $person?->fullName(),
            'learner_number' => $this->numbering->find($tenantId, (string) $row['person_id']),
            'from_establishment_id' => (string) $row['from_establishment_id'],
            'to_establishment_id' => (string) $row['to_establishment_id'],
            'state' => (string) $row['state'],
            'requested_at' => (string) $row['requested_at'],
            'decided_at' => $row['decided_at'],
            'arrival_on' => $row['arrival_on'],
            'refusal_reason' => $row['refusal_reason'],
            'exeat_document_id' => $row['exeat_document_id'],
            'to_structure_id' => $row['to_structure_id'],
        ];
    }

    private static function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date d\'accueil au format AAAA-MM-JJ attendue.');
        }
    }
}
