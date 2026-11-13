<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Workshops\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Core\Support\Ulid;
use Scholaris\Modules\Apps\Secondary\Setup\Contracts\ClassProfiles;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Perimeter;
use Scholaris\Modules\Apps\Secondary\Support\ScopedByClass;
use Scholaris\Modules\Apps\Secondary\Support\SecondaryClock;
use Scholaris\Modules\Apps\Secondary\Support\Store;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/**
 * Stages (T910) : entreprises d'accueil, stages d'un élève inscrit dans une classe de l'établissement, convention (référence de
 * document), appréciation du tuteur, note et coefficient paramétrés, visites. Cloisonné par client et établissement.
 */
final class InternshipService
{
    public const COMPANIES = 'se_companies';
    public const INTERNSHIPS = 'se_internships';
    public const VISITS = 'se_internship_visits';

    public function __construct(
        private readonly Store $store,
        private readonly Access $access,
        private readonly SecondaryClock $clock,
        private readonly ClassProfiles $classes,
        private readonly EnrollmentDirectory $enrollments,
        private readonly ScopedByClass $scoped,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCompany(Principal $principal, string $establishmentId, Perimeter $perimeter, array $data): array
    {
        $this->access->assertWhole($principal, $perimeter, 'internship_company');
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::COMPANIES, $principal->tenantId, $establishmentId, [
            'id' => $id, 'label' => $data['label'], 'sector' => $data['sector'] ?? null, 'address' => $data['address'] ?? null,
            'contact_name' => $data['contact_name'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null, 'status' => 'active',
            'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.internship.company.created', 'internship_company', $id, null, ['label' => $data['label']]);

        return $this->company($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function company(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::COMPANIES, $tenantId, $establishmentId, $id, 'Entreprise introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function companies(string $tenantId, string $establishmentId): array
    {
        return $this->store->select(self::COMPANIES, $tenantId, $establishmentId, [], 'label', 500);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateCompany(Principal $principal, string $establishmentId, Perimeter $perimeter, string $id, array $changes): array
    {
        $this->access->assertWhole($principal, $perimeter, 'internship_company');
        $before = $this->company($principal->tenantId, $establishmentId, $id);
        if ($changes === []) {
            throw HttpException::validation(['company' => ['Aucun champ modifiable fourni.']]);
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(self::COMPANIES, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $after = $this->company($principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.internship.company.updated', 'internship_company', $id, $before, $after);

        return $after;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createInternship(Principal $principal, string $establishmentId, Perimeter $perimeter, array $data): array
    {
        $this->access->assertWritableClass($principal, $perimeter, (string) $data['structure_id'], 'internship', (string) $data['person_id']);
        if ($data['ends_on'] < $data['starts_on']) {
            throw HttpException::validation(['ends_on' => ['La fin du stage doit suivre son début.']]);
        }
        $this->assertLearner($principal->tenantId, $establishmentId, (string) $data['structure_id'], (string) $data['person_id']);
        $company = $this->company($principal->tenantId, $establishmentId, (string) $data['company_id']);
        if ($company['status'] !== 'active') {
            throw HttpException::validation(['company_id' => ['Entreprise inactive.']]);
        }
        $id = (string) new Ulid();
        $now = $this->clock->now();
        $this->store->insert(self::INTERNSHIPS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'person_id' => $data['person_id'], 'structure_id' => $data['structure_id'], 'company_id' => $data['company_id'], 'tutor_name' => $data['tutor_name'],
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'agreement_document_id' => $data['agreement_document_id'] ?? null,
            'tutor_appraisal' => null, 'report_received_on' => null, 'score' => null, 'score_coefficient' => $data['score_coefficient'] ?? null,
            'assessment_id' => null, 'status' => 'planned', 'created_by' => $principal->userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->access->audit($principal, 'secondary.internship.created', 'internship', $id, null, ['person_id' => $data['person_id'], 'company_id' => $data['company_id']]);

        return $this->internship($principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function internship(string $tenantId, string $establishmentId, string $id): array
    {
        $row = self::withWeighted($this->internshipRecord($tenantId, $establishmentId, $id));
        $row['company'] = $this->store->find(self::COMPANIES, $tenantId, $establishmentId, (string) $row['company_id']);

        return $row;
    }

    /** @return list<array<string, mixed>> stages des classes du périmètre du lecteur */
    public function internships(string $tenantId, string $establishmentId, Perimeter $perimeter): array
    {
        [$extra, $params] = $this->scoped->sqlFilter($perimeter, 'structure_id');
        $companies = array_column($this->companies($tenantId, $establishmentId), null, 'id');

        return array_map(
            static fn (array $row): array => ['company' => $companies[(string) $row['company_id']] ?? null] + self::withWeighted($row),
            $this->store->select(self::INTERNSHIPS, $tenantId, $establishmentId, [], 'starts_on DESC', 500, $extra, $params),
        );
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateInternship(Principal $principal, string $establishmentId, string $id, array $changes): array
    {
        $before = $this->internshipRecord($principal->tenantId, $establishmentId, $id);
        if ($changes === []) {
            throw HttpException::validation(['internship' => ['Aucun champ modifiable fourni.']]);
        }
        $starts = (string) ($changes['starts_on'] ?? $before['starts_on']);
        $ends = (string) ($changes['ends_on'] ?? $before['ends_on']);
        if ($ends < $starts) {
            throw HttpException::validation(['ends_on' => ['La fin du stage doit suivre son début.']]);
        }
        if (isset($changes['company_id'])) {
            $this->company($principal->tenantId, $establishmentId, (string) $changes['company_id']);
        }
        $changes['updated_at'] = $this->clock->now();
        $this->store->update(self::INTERNSHIPS, $principal->tenantId, $establishmentId, $changes, ['id' => $id]);
        $after = $this->internship($principal->tenantId, $establishmentId, $id);
        $this->access->audit($principal, 'secondary.internship.updated', 'internship', $id, $before, $changes);

        return $after;
    }

    /** @return array<string, mixed> */
    public function visit(Principal $principal, string $establishmentId, string $internshipId, string $visitorId, string $date, string $notes): array
    {
        $this->internshipRecord($principal->tenantId, $establishmentId, $internshipId);
        $id = (string) new Ulid();
        $this->store->insert(self::VISITS, $principal->tenantId, $establishmentId, [
            'id' => $id, 'internship_id' => $internshipId, 'visitor_person_id' => $visitorId, 'visited_on' => $date, 'notes' => $notes,
            'created_by' => $principal->userId, 'created_at' => $this->clock->now(),
        ]);
        $this->access->audit($principal, 'secondary.internship.visit.recorded', 'internship_visit', $id, null, ['internship_id' => $internshipId, 'visited_on' => $date]);

        return $this->store->findOrFail(self::VISITS, $principal->tenantId, $establishmentId, $id);
    }

    /** @return array<string, mixed> */
    public function internshipRecord(string $tenantId, string $establishmentId, string $id): array
    {
        return $this->store->findOrFail(self::INTERNSHIPS, $tenantId, $establishmentId, $id, 'Stage introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public function visits(string $tenantId, string $establishmentId, string $internshipId): array
    {
        $this->internshipRecord($tenantId, $establishmentId, $internshipId);

        return $this->store->select(self::VISITS, $tenantId, $establishmentId, ['internship_id' => $internshipId], 'visited_on DESC', 500);
    }

    /** La classe est une classe du secondaire de l'établissement et l'élève y est inscrit (inscription active de l'année). */
    private function assertLearner(string $tenantId, string $establishmentId, string $structureId, string $personId): void
    {
        $class = $this->classes->find($tenantId, $establishmentId, $structureId);
        if ($class === null) {
            throw HttpException::notFound('Classe introuvable dans cet établissement.');
        }
        foreach ($this->enrollments->forPerson($tenantId, $personId) as $enrollment) {
            if ($enrollment->establishmentId === $establishmentId && $enrollment->structureId === $structureId
                && $enrollment->academicYearId === $class->academicYearId && in_array($enrollment->status, [EnrollmentInfo::ACTIVE, EnrollmentInfo::CONDITIONAL], true)) {
                return;
            }
        }

        throw HttpException::validation(['person_id' => ['Élève non inscrit dans cette classe.']]);
    }

    /**
     * Note de stage pondérée par le coefficient paramétré du stage (T910) : note × coefficient, sinon null.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withWeighted(array $row): array
    {
        $row['weighted_score'] = $row['score'] === null || $row['score_coefficient'] === null ? null : round((float) $row['score'] * (float) $row['score_coefficient'], 2);

        return $row;
    }
}
