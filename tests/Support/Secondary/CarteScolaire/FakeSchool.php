<?php

declare(strict_types=1);

namespace Tests\Support\Secondary\CarteScolaire;

use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDemographics;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Doublure des contrats People et Organisation pour la carte scolaire : élèves fictifs (nom, prénoms, date de naissance) inscrits
 * dans une classe rattachée à un niveau. Données fictives.
 */
final class FakeSchool implements PersonDirectory, PersonDemographics, OrgUnitTree
{
    /** @var array<string, PersonSummary> */
    private array $people = [];

    /** @var array<string, string> */
    private array $births = [];

    /** @var list<EnrollmentInfo> */
    public array $enrollments = [];

    /** @var array<string, StructureInfo> */
    private array $structures = [];

    /** @var array<string, string> classe => niveau */
    private array $levels = [];

    public function level(string $establishmentId, string $classId, string $levelId, string $levelName): void
    {
        $this->structures[$levelId] = new StructureInfo($levelId, $establishmentId, 'level', $levelName, null, null, null);
        $this->structures[$classId] = new StructureInfo($classId, $establishmentId, 'class', $levelName . ' A', null, $levelId, null);
        $this->levels[$classId] = $levelId;
    }

    public function student(string $tenantId, string $establishmentId, string $personId, string $lastName, string $firstName, ?string $birthDate, string $classId = 'classe-1'): void
    {
        $this->people[$personId] = new PersonSummary($personId, $tenantId, $firstName, $lastName);
        if ($birthDate !== null) {
            $this->births[$personId] = $birthDate;
        }
        $this->enrollments[] = new EnrollmentInfo('ins-' . $personId, $tenantId, $personId, $establishmentId, $classId, 'ay1', EnrollmentInfo::ACTIVE);
    }

    public function find(string $tenantId, string $id): ?PersonSummary
    {
        $person = $this->people[$id] ?? null;

        return $person !== null && $person->tenantId === $tenantId ? $person : null;
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

    public function ofPersons(string $tenantId, array $personIds): array
    {
        $rows = [];
        foreach ($personIds as $id) {
            if ($this->find($tenantId, $id) !== null) {
                $rows[$id] = ['gender' => null, 'birth_date' => $this->births[$id] ?? null];
            }
        }

        return $rows;
    }

    public function descendantsOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        return [];
    }

    public function structuresOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        return [];
    }

    public function structureDescendantsOf(string $tenantId, string $establishmentId, string $structureId): array
    {
        return [];
    }

    public function ancestorsOf(string $tenantId, string $establishmentId, string $orgUnitId): array
    {
        return [];
    }

    public function structure(string $tenantId, string $establishmentId, string $structureId): ?StructureInfo
    {
        return $this->structures[$structureId] ?? null;
    }

    public function trackOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return null;
    }

    public function levelOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return $this->levels[$structureId] ?? null;
    }
}
