<?php

declare(strict_types=1);

namespace Tests\Support\Nursery;

use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;
use Scholaris\Modules\Socle\People\Contracts\ConsentChecker;
use Scholaris\Modules\Socle\People\Contracts\ConsentRecorder;
use Scholaris\Modules\Socle\People\Contracts\FoodAllergies;
use Scholaris\Modules\Socle\People\Contracts\LearnerWriter;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Doublure en mémoire du module People pour les tests de la Maternelle privée : personnes, garants, naissance, allergies,
 * consentements, lien compte-personne et écriture d'un enfant (LearnerWriter). Borne chaque lecture au client.
 */
final class WorldPeople implements PersonDirectory, BirthInfo, FoodAllergies, ConsentChecker, ConsentRecorder, UserPersonLink, LearnerWriter
{
    /** @var array<string, PersonSummary> */
    private array $people = [];
    /** @var array<string, string> */
    private array $births = [];
    /** @var array<string, list<string>> garant => enfants (clé « client|garant ») */
    private array $learners = [];
    /** @var array<string, string> */
    private array $allergies = [];
    /** @var array<string, string> compte => personne (clé « client|compte ») */
    private array $accounts = [];
    /** @var array<string, array{id: string, revoked: bool}> */
    private array $consents = [];
    private int $consentSeq = 0;

    public function __construct(private readonly WorldEnrollments $enrollments)
    {
    }

    public function add(string $tenantId, string $id, string $firstName, string $lastName, ?string $birthDate = null, ?string $phone = null, ?string $gender = null): void
    {
        $this->people[$tenantId . '|' . $id] = new PersonSummary($id, $tenantId, $firstName, $lastName, $phone, null, 'fr', $gender);
        if ($birthDate !== null) {
            $this->births[$tenantId . '|' . $id] = $birthDate;
        }
    }

    public function link(string $tenantId, string $guarantorId, string $learnerId): void
    {
        $this->learners[$tenantId . '|' . $guarantorId][] = $learnerId;
    }

    public function account(string $tenantId, string $userId, string $personId): void
    {
        $this->accounts[$tenantId . '|' . $userId] = $personId;
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        return $this->people[$tenantId . '|' . $personId] ?? null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?PersonSummary => $this->find($tenantId, $id), $personIds)));
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        $out = [];
        foreach ($this->learners as $key => $learnerIds) {
            [$tenant, $guarantor] = explode('|', $key, 2);
            if ($tenant === $tenantId && in_array($learnerId, $learnerIds, true) && ($person = $this->find($tenantId, $guarantor)) !== null) {
                $out[] = $person;
            }
        }

        return $out;
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return $this->findMany($tenantId, $this->learners[$tenantId . '|' . $guarantorId] ?? []);
    }

    public function birthDate(string $tenantId, string $personId): ?string
    {
        return $this->births[$tenantId . '|' . $personId] ?? null;
    }

    public function isAdult(string $tenantId, string $personId, string $atDate): ?bool
    {
        $birth = $this->birthDate($tenantId, $personId);

        return $birth === null ? null : (new \DateTimeImmutable($birth))->diff(new \DateTimeImmutable($atDate))->y >= self::ADULT_AGE;
    }

    public function forLearners(string $tenantId, array $learnerIds): array
    {
        $out = [];
        foreach ($learnerIds as $id) {
            if ($this->find($tenantId, $id) !== null) {
                $out[$id] = $this->allergies[$tenantId . '|' . $id] ?? null;
            }
        }

        return $out;
    }

    public function declare(string $tenantId, string $learnerId, ?string $text, string $actorId): void
    {
        if ($text === null) {
            unset($this->allergies[$tenantId . '|' . $learnerId]);

            return;
        }
        $this->allergies[$tenantId . '|' . $learnerId] = $text;
    }

    public function hasConsent(string $tenantId, string $personId, string $purpose): bool
    {
        $consent = $this->consents[$tenantId . '|' . $personId . '|' . $purpose] ?? null;

        return $consent !== null && !$consent['revoked'];
    }

    public function grant(string $tenantId, string $personId, string $purpose, string $actorId, ?string $evidenceRef = null): array
    {
        $id = 'consent-' . ++$this->consentSeq;
        $this->consents[$tenantId . '|' . $personId . '|' . $purpose] = ['id' => $id, 'revoked' => false];

        return ['id' => $id, 'person_id' => $personId, 'purpose' => $purpose, 'granted_at' => '2026-10-01T08:00:00+01:00', 'granted_by' => $actorId, 'revoked_at' => null, 'revoked_by' => null, 'evidence_ref' => $evidenceRef];
    }

    public function revoke(string $tenantId, string $consentId, string $actorId): array
    {
        foreach ($this->consents as $key => $consent) {
            if (str_starts_with($key, $tenantId . '|') && $consent['id'] === $consentId) {
                $this->consents[$key]['revoked'] = true;
                [, $personId, $purpose] = explode('|', $key, 3);

                return ['id' => $consentId, 'person_id' => $personId, 'purpose' => $purpose, 'granted_at' => '2026-10-01T08:00:00+01:00', 'granted_by' => null, 'revoked_at' => '2026-10-02T08:00:00+01:00', 'revoked_by' => $actorId, 'evidence_ref' => null];
            }
        }
        throw new \RuntimeException('Consentement inconnu.');
    }

    public function hasWrittenConsent(string $tenantId, string $personId, string $purpose): bool
    {
        return $this->hasConsent($tenantId, $personId, $purpose);
    }

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->accounts[$tenantId . '|' . $userId] ?? null;
    }

    public function createLearner(string $tenantId, array $draft, array $guardians, ?string $actorId, bool $confirmDifferent = false): array
    {
        $id = (string) new Ulid();
        $this->add($tenantId, $id, (string) $draft['first_name'], (string) $draft['last_name'], isset($draft['birth_date']) ? (string) $draft['birth_date'] : null, null, isset($draft['gender']) ? (string) $draft['gender'] : null);
        $guardianIds = [];
        foreach ($guardians as $guardian) {
            $guardianId = $guardian['person_id'] ?? null;
            if ($guardianId === null) {
                $identity = (array) ($guardian['identity'] ?? []);
                $guardianId = (string) new Ulid();
                $this->add($tenantId, $guardianId, (string) ($identity['first_name'] ?? 'Parent'), (string) ($identity['last_name'] ?? 'Test'), null, isset($identity['phone']) ? (string) $identity['phone'] : null);
            }
            $this->link($tenantId, (string) $guardianId, $id);
            $guardianIds[] = (string) $guardianId;
        }

        return ['person_id' => $id, 'guardian_ids' => $guardianIds];
    }

    public function enroll(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, string $date, ?string $actorId): string
    {
        return $this->enrollments->add($tenantId, (string) new Ulid(), $personId, $establishmentId, $structureId, $academicYearId);
    }

    public function closeEnrollment(string $tenantId, string $enrollmentId, string $status, string $date): void
    {
        $this->enrollments->close($tenantId, $enrollmentId, $status);
    }
}
