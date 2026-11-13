<?php

declare(strict_types=1);

namespace Tests\Support\Higher\Internships;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentIssuer;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\IssuedDocuments;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\EnrolmentInfo;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentDirectory;
use Scholaris\Modules\Apps\Higher\Enrolment\Contracts\StudentInfo;
use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Doublures des contrats lus par Internships : inscriptions (Enrolment), comptes liés (Identity), personnes (People),
 * émission et registre des documents de l'IPES (Documents : DOC-20 à 23 émis, quitus DOC-30).
 */
final class InternshipFakes implements StudentDirectory, UserPersonLink, PersonDirectory, HigherDocumentIssuer, IssuedDocuments
{
    /** @var array<string, EnrolmentInfo> */
    public array $enrolments = [];

    /** @var array<string, string> « client:utilisateur » => personne */
    public array $links = [];

    /** @var array<string, string> personne => nom */
    public array $names = [];

    /** @var list<array{code: string, person_id: string, data: array<string, scalar|null>}> */
    public array $issuedDocs = [];

    /** @var array<string, true> personnes munies d'un quitus valide */
    public array $quitus = [];

    public function enrol(string $personId, string $schoolId, string $programId, string $levelId, ?string $groupId, string $year, string $status = 'enrolled'): void
    {
        $this->enrolments[$personId] = new EnrolmentInfo('enr-' . $personId, $personId, 'MAT-' . $personId, $year, $schoolId, $programId, $levelId, $groupId, 'day', $status, null, 1, null);
    }

    public function link(string $tenantId, string $userId, string $personId): void
    {
        $this->links[$tenantId . ':' . $userId] = $personId;
    }

    /** @return list<string> codes émis */
    public function codes(): array
    {
        return array_column($this->issuedDocs, 'code');
    }

    public function student(string $tenantId, string $establishmentId, string $personId): ?StudentInfo
    {
        return null;
    }

    public function byMatricule(string $tenantId, string $establishmentId, string $matricule): ?StudentInfo
    {
        return null;
    }

    public function enrolmentsOf(string $tenantId, string $establishmentId, string $personId): array
    {
        return isset($this->enrolments[$personId]) ? [$this->enrolments[$personId]] : [];
    }

    public function enrolment(string $tenantId, string $establishmentId, string $enrolmentId): ?EnrolmentInfo
    {
        foreach ($this->enrolments as $enrolment) {
            if ($enrolment->id === $enrolmentId) {
                return $enrolment;
            }
        }

        return null;
    }

    public function enrolledIn(string $tenantId, string $establishmentId, string $structureId, string $academicYearId): array
    {
        return array_values(array_filter($this->enrolments, static fn (EnrolmentInfo $e): bool => $e->academicYearId === $academicYearId && ($e->groupId === $structureId || ($e->groupId === null && $e->levelId === $structureId))));
    }

    public function sharesWithGuarantor(string $tenantId, string $establishmentId, string $personId): bool
    {
        return false;
    }

    public function personIdForUser(string $tenantId, string $userId): ?string
    {
        return $this->links[$tenantId . ':' . $userId] ?? null;
    }

    public function find(string $tenantId, string $personId): ?PersonSummary
    {
        if (!isset($this->names[$personId])) {
            return null;
        }
        [$first, $last] = explode(' ', $this->names[$personId], 2) + [1 => ''];

        return new PersonSummary($personId, $tenantId, $first, $last);
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?PersonSummary => $this->find($tenantId, $id), $personIds)));
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return [];
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return [];
    }

    public function issueFor(string $tenantId, string $establishmentId, string $code, string $personId, string $schoolId, ?string $programId, ?string $levelId, array $data, string $issuedBy, ?string $locale = null): array
    {
        $this->issuedDocs[] = ['code' => $code, 'person_id' => $personId, 'data' => $data];

        return ['id' => 'doc-' . count($this->issuedDocs), 'code' => $code, 'status' => 'valid'];
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return isset($this->quitus[$personId]) ? [['code' => 'DOC-30', 'status' => 'valid', 'person_id' => $personId]] : [];
    }
}
