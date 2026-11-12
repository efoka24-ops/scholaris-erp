<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Application;

use DateInterval;
use DateTimeImmutable;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Services\Contracts\PeExemptions;
use Scholaris\Modules\Apps\Secondary\Services\Domain\ServicesDefaults;
use Scholaris\Modules\Apps\Secondary\Services\Infrastructure\HealthCipher;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;

/**
 * Infirmerie [infirmary] (T973, EX-SEC-66, CDC « Mineurs ») :
 *  - passages (motif, suite donnée, appel des parents, notes chiffrées) et dossier de santé (allergies, affections, traitements,
 *    consignes d'urgence, chiffrés) : lus et écrits par l'infirmier SEUL (`secondary.infirmary.manage`) ; tout autre rôle, chef
 *    d'établissement compris, reçoit 403 ;
 *  - CHAQUE consultation (liste des passages, passage, dossier) et chaque écriture laisse une ligne dans `se_health_access_log`
 *    (qui, quand, quel élève) et une entrée d'audit ; le journal ne contient aucune donnée de santé ;
 *  - une dispense d'EPS saisie au passage est lisible sans motif par le contrat PeExemptions (Grades : code `exempt`).
 */
final class Infirmary implements PeExemptions
{
    public const NURSE = ['secondary.infirmary.manage'];
    /** Journal des consultations : l'infirmier et la direction (aucune donnée de santé dans le journal). */
    public const LOG_READERS = ['secondary.infirmary.manage', 'secondary.settings.manage'];
    /** Dispenses d'EPS sans motif : infirmier, enseignants, censeur, surveillant général, scolarité. */
    public const EXEMPTION_READERS = ['secondary.infirmary.manage', 'secondary.grades.enter', 'secondary.grades.validate', 'secondary.attendance.manage', 'secondary.attendance.take', 'secondary.enrol.manage'];
    public const VISITS = 'se_infirmary_visits';
    public const RECORDS = 'se_health_records';
    public const LOG = 'se_health_access_log';
    private const RECORD_FIELDS = ['allergies', 'conditions', 'treatments', 'emergency_instructions'];

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly HealthCipher $cipher,
        private readonly SecondaryClock $clock,
        private readonly EnrollmentDirectory $enrollments,
        private readonly PersonDirectory $people,
        private readonly UserPersonLink $userPeople,
    ) {
    }

    /**
     * Ligne du journal des consultations avec les noms lisibles (auteur de la consultation et élève concerné), jamais de
     * donnée de santé. Un compte sans personne liée garde un nom null : l'écran affiche alors un libellé générique.
     *
     * @param array<string, mixed> $row
     * @param array<string, string|null> $names noms déjà résolus le temps d'une page (clés « u: » et « p: »)
     * @return array<string, mixed>
     */
    public function logView(array $row, array &$names): array
    {
        $tenantId = (string) $row['tenant_id'];
        $userId = (string) $row['user_id'];
        $personId = $row['person_id'] === null ? null : (string) $row['person_id'];
        if (!array_key_exists('u:' . $userId, $names)) {
            $linked = $this->userPeople->personIdForUser($tenantId, $userId);
            $names['u:' . $userId] = $linked === null ? null : $this->people->find($tenantId, $linked)?->fullName();
        }
        if ($personId !== null && !array_key_exists('p:' . $personId, $names)) {
            $names['p:' . $personId] = $this->people->find($tenantId, $personId)?->fullName();
        }

        return [
            'id' => (string) $row['id'], 'person_id' => $personId, 'student_name' => $personId === null ? null : $names['p:' . $personId],
            'user_id' => $userId, 'user_name' => $names['u:' . $userId], 'access_kind' => (string) $row['access_kind'],
            'object_id' => $row['object_id'], 'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $data person_id, visited_at?, reason_code, outcome, parent_called, notes?, pe_exempt_until?
     * @return array<string, mixed>
     */
    public function recordVisit(Principal $principal, string $establishmentId, array $data): array
    {
        $this->nurse($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $personId = (string) $data['person_id'];
        $enrollment = $this->liveEnrollment($tenantId, $establishmentId, $personId)
            ?? throw HttpException::validation(['person_id' => ['Élève sans inscription en cours dans cet établissement.']]);
        $visitedAt = isset($data['visited_at']) ? (string) $data['visited_at'] : $this->clock->now();
        $day = substr($visitedAt, 0, 10);
        $until = isset($data['pe_exempt_until']) ? (string) $data['pe_exempt_until'] : null;
        if ($until !== null) {
            $limit = (new DateTimeImmutable($day))->add(new DateInterval('P' . ServicesDefaults::MAX_EXEMPTION_DAYS . 'D'))->format('Y-m-d');
            if ($until < $day || $until > $limit) {
                throw HttpException::validation(['pe_exempt_until' => [sprintf('Fin de dispense entre le jour du passage et %d jours après.', ServicesDefaults::MAX_EXEMPTION_DAYS)]]);
            }
        }
        $notes = isset($data['notes']) ? (string) $data['notes'] : null;
        $id = (string) new Ulid();
        $this->store->insert(self::VISITS, $tenantId, $establishmentId, [
            'id' => $id, 'person_id' => $personId, 'class_id' => $enrollment->structureId, 'visited_at' => $visitedAt,
            'reason_code' => (string) $data['reason_code'], 'outcome' => (string) $data['outcome'], 'parent_called' => ($data['parent_called'] ?? false) === true ? 1 : 0,
            'notes_encrypted' => $notes === null ? null : $this->cipher->encrypt($notes), 'pe_exempt_from' => $until === null ? null : $day,
            'pe_exempt_until' => $until, 'nurse_user_id' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->log($principal, $establishmentId, $personId, 'write_visit', $id);
        $this->access->audit($principal, 'secondary.infirmary.visit.recorded', 'infirmary_visit', $id, null, ['person_id' => $personId, 'pe_exemption' => $until !== null]);

        return $this->visitView($this->store->findOrFail(self::VISITS, $tenantId, $establishmentId, $id), true);
    }

    /** @return array<string, mixed> */
    public function visit(Principal $principal, string $establishmentId, string $id): array
    {
        $this->nurse($principal, $establishmentId);
        $row = $this->store->findOrFail(self::VISITS, $principal->tenantId, $establishmentId, $id, 'Passage introuvable.');
        $this->log($principal, $establishmentId, (string) $row['person_id'], 'read_visit', $id);

        return $this->visitView($row, true);
    }

    /**
     * Vérifie le droit de l'infirmier et journalise la consultation d'une liste (une ligne par élève filtré, sinon une ligne « liste »).
     */
    public function beforeList(Principal $principal, string $establishmentId, ?string $personId): void
    {
        $this->nurse($principal, $establishmentId);
        $this->log($principal, $establishmentId, $personId, 'read_visits', null);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function visitView(array $row, bool $withNotes = false): array
    {
        $person = $this->people->find((string) $row['tenant_id'], (string) $row['person_id']);
        $view = [
            'id' => (string) $row['id'], 'person_id' => (string) $row['person_id'], 'student_name' => $person === null ? null : $person->fullName(),
            'class_id' => $row['class_id'], 'visited_at' => (string) $row['visited_at'], 'reason_code' => (string) $row['reason_code'],
            'outcome' => (string) $row['outcome'], 'parent_called' => (int) $row['parent_called'] === 1, 'pe_exempt_from' => $row['pe_exempt_from'],
            'pe_exempt_until' => $row['pe_exempt_until'], 'nurse_user_id' => (string) $row['nurse_user_id'],
        ];
        if ($withNotes) {
            $view['notes'] = is_string($row['notes_encrypted'] ?? null) ? $this->cipher->decrypt((string) $row['notes_encrypted']) : null;
        }

        return $view;
    }

    /** @return array<string, mixed> */
    public function record(Principal $principal, string $establishmentId, string $personId): array
    {
        $this->nurse($principal, $establishmentId);
        $this->known($principal->tenantId, $establishmentId, $personId);
        $row = $this->store->select(self::RECORDS, $principal->tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        $this->log($principal, $establishmentId, $personId, 'read_record', $row === null ? null : (string) $row['id']);
        $this->access->audit($principal, 'secondary.health.record.read', 'health_record', $personId);

        return $this->recordView($personId, $row);
    }

    /**
     * @param array<string, string|null> $fields allergies, conditions, treatments, emergency_instructions
     * @return array<string, mixed>
     */
    public function saveRecord(Principal $principal, string $establishmentId, string $personId, array $fields): array
    {
        $this->nurse($principal, $establishmentId);
        $tenantId = $principal->tenantId;
        $this->known($tenantId, $establishmentId, $personId);
        $content = [];
        foreach (self::RECORD_FIELDS as $field) {
            $content[$field] = isset($fields[$field]) ? (string) $fields[$field] : null;
        }
        $encrypted = $this->cipher->encrypt(json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $now = $this->clock->now();
        $existing = $this->store->select(self::RECORDS, $tenantId, $establishmentId, ['person_id' => $personId], 'id', 1)[0] ?? null;
        if ($existing === null) {
            $id = (string) new Ulid();
            $this->store->insert(self::RECORDS, $tenantId, $establishmentId, ['id' => $id, 'person_id' => $personId, 'content_encrypted' => $encrypted, 'updated_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $id = (string) $existing['id'];
            $this->store->update(self::RECORDS, $tenantId, $establishmentId, ['content_encrypted' => $encrypted, 'updated_by' => $principal->userId, 'updated_at' => $now], ['id' => $id]);
        }
        $this->log($principal, $establishmentId, $personId, 'write_record', $id);
        $this->access->audit($principal, 'secondary.health.record.saved', 'health_record', $personId);

        return $this->recordView($personId, $this->store->findOrFail(self::RECORDS, $tenantId, $establishmentId, $id));
    }

    /**
     * @param list<string> $permissions
     */
    public function requireAny(Principal $principal, string $establishmentId, array $permissions): void
    {
        $this->access->require($principal, $establishmentId, $permissions);
    }

    public function isExempt(string $tenantId, string $establishmentId, string $personId, string $date): bool
    {
        return $this->exemptOn($tenantId, $establishmentId, [$personId], $date) !== [];
    }

    public function exemptOn(string $tenantId, string $establishmentId, array $personIds, string $date): array
    {
        if ($personIds === []) {
            return [];
        }
        $wanted = array_flip($personIds);
        $out = [];
        $rows = $this->store->select(self::VISITS, $tenantId, $establishmentId, [], 'pe_exempt_until', 10000, 'pe_exempt_from IS NOT NULL AND pe_exempt_from <= ? AND pe_exempt_until >= ?', [$date, $date]);
        foreach ($rows as $row) {
            $person = (string) $row['person_id'];
            if (isset($wanted[$person]) && (string) $row['pe_exempt_until'] > ($out[$person] ?? '')) {
                $out[$person] = (string) $row['pe_exempt_until'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function recordView(string $personId, ?array $row): array
    {
        $content = [];
        if ($row !== null) {
            $decoded = json_decode($this->cipher->decrypt((string) $row['content_encrypted']), true);
            $content = is_array($decoded) ? $decoded : [];
        }
        $view = ['person_id' => $personId, 'exists' => $row !== null];
        foreach (self::RECORD_FIELDS as $field) {
            $view[$field] = isset($content[$field]) && is_string($content[$field]) ? $content[$field] : null;
        }

        return $view + ['updated_by' => $row['updated_by'] ?? null, 'updated_at' => $row['updated_at'] ?? null];
    }

    private function nurse(Principal $principal, string $establishmentId): void
    {
        try {
            $this->access->require($principal, $establishmentId, self::NURSE);
        } catch (HttpException $refusal) {
            $this->access->audit($principal, 'secondary.health.denied', 'health_record', $establishmentId);

            throw $refusal;
        }
    }

    private function known(string $tenantId, string $establishmentId, string $personId): void
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->tenantId === $tenantId && $enrollment->establishmentId === $establishmentId) {
                return;
            }
        }

        throw HttpException::notFound('Élève introuvable dans cet établissement.');
    }

    private function liveEnrollment(string $tenantId, string $establishmentId, string $personId): ?EnrollmentInfo
    {
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->tenantId === $tenantId && $enrollment->establishmentId === $establishmentId
                && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return $enrollment;
            }
        }

        return null;
    }

    private function log(Principal $principal, string $establishmentId, ?string $personId, string $kind, ?string $objectId): void
    {
        $this->store->insert(self::LOG, $principal->tenantId, $establishmentId, [
            'id' => (string) new Ulid(), 'person_id' => $personId, 'user_id' => $principal->userId, 'access_kind' => $kind,
            'object_id' => $objectId, 'created_at' => $this->clock->now(),
        ]);
    }
}
