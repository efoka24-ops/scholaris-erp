<?php

declare(strict_types=1);

namespace Tests\Feature\Secondary\Discipline;

use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceSummary;
use Scholaris\Modules\Socle\Attendance\Contracts\AttendanceTotals;
use Scholaris\Modules\Socle\Organisation\Contracts\OrgUnitTree;
use Scholaris\Modules\Socle\Organisation\Contracts\StructureInfo;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;
use Scholaris\Modules\Socle\People\Contracts\PersonDirectory;
use Scholaris\Modules\Socle\People\Contracts\PersonSummary;

/**
 * Doublure des contrats du socle pour la discipline : élèves et garants fictifs, classes, inscriptions et synthèses d'appel
 * (séances validées) par élève, fixées par le test. Données fictives.
 */
final class DisciplineSchool implements PersonDirectory, OrgUnitTree, AttendanceTotals
{
    /** @var array<string, PersonSummary> */
    private array $people = [];

    /** @var array<string, list<string>> élève => garants */
    private array $guarantors = [];

    /** @var list<EnrollmentInfo> */
    public array $enrollments = [];

    /** @var array<string, StructureInfo> */
    private array $structures = [];

    /** @var array<string, AttendanceSummary> « client:établissement:élève » => synthèse */
    private array $summaries = [];

    public function classe(string $establishmentId, string $classId, string $name): void
    {
        $this->structures[$classId] = new StructureInfo($classId, $establishmentId, 'class', $name, null, null, null);
    }

    public function student(string $tenantId, string $establishmentId, string $personId, string $lastName, string $firstName, string $classId, ?string $guardianId = null): void
    {
        $this->people[$personId] = new PersonSummary($personId, $tenantId, $firstName, $lastName);
        $this->enrollments[] = new EnrollmentInfo('ins-' . $personId, $tenantId, $personId, $establishmentId, $classId, 'ay1', EnrollmentInfo::ACTIVE);
        if ($guardianId !== null) {
            $this->people[$guardianId] = new PersonSummary($guardianId, $tenantId, 'Parent', $lastName, '+237600000000');
            $this->guarantors[$personId][] = $guardianId;
        }
    }

    /** Heures d'appel : `absences` séances d'absence dont `unjustified` non justifiées, sur `sessions` séances validées. */
    public function attendance(string $tenantId, string $establishmentId, string $personId, int $sessions, int $absences, int $unjustified, int $lates = 0): void
    {
        $this->summaries[$tenantId . ':' . $establishmentId . ':' . $personId] = new AttendanceSummary($sessions, $absences, $unjustified, $lates);
    }

    public function byLearner(string $tenantId, string $establishmentId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->summaries as $key => $summary) {
            [$tenant, $establishment, $person] = explode(':', $key, 3);
            if ($tenant === $tenantId && $establishment === $establishmentId) {
                $out[$person] = $summary;
            }
        }

        return $out;
    }

    public function find(string $tenantId, string $id): ?PersonSummary
    {
        $person = $this->people[$id] ?? null;

        return $person !== null && $person->tenantId === $tenantId ? $person : null;
    }

    public function findMany(string $tenantId, array $personIds): array
    {
        $found = [];
        foreach ($personIds as $id) {
            $person = $this->find($tenantId, $id);
            if ($person !== null) {
                $found[] = $person;
            }
        }

        return $found;
    }

    public function guarantorsOf(string $tenantId, string $learnerId): array
    {
        return $this->findMany($tenantId, $this->guarantors[$learnerId] ?? []);
    }

    public function learnersOf(string $tenantId, string $guarantorId): array
    {
        return [];
    }

    /** @return list<string> */
    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        $ids = [];
        foreach ($this->enrollments as $e) {
            if ($e->tenantId === $tenantId && $e->structureId === $structureId) {
                $ids[] = $e->personId;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        $ids = [];
        foreach ($this->enrollments as $e) {
            if ($e->tenantId === $tenantId && $e->establishmentId === $establishmentId) {
                $ids[] = $e->personId;
            }
        }

        return $ids;
    }

    /** @return list<EnrollmentInfo> */
    public function forPerson(string $tenantId, string $personId): array
    {
        return array_values(array_filter($this->enrollments, static fn (EnrollmentInfo $e): bool => $e->tenantId === $tenantId && $e->personId === $personId));
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
        $structure = $this->structures[$structureId] ?? null;

        return $structure !== null && $structure->establishmentId === $establishmentId ? $structure : null;
    }

    public function trackOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return null;
    }

    public function levelOf(string $tenantId, string $establishmentId, string $structureId): ?string
    {
        return null;
    }
}
