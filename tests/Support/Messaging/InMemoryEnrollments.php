<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/** Doublure en mémoire de l'annuaire des inscriptions (module People). */
final class InMemoryEnrollments implements EnrollmentDirectory
{
    /** @var array<string, list<string>> */
    private array $structures = [];
    /** @var array<string, list<string>> */
    private array $establishments = [];

    public function enrol(string $tenant, string $structure, string $establishment, string $person): void
    {
        $this->structures[$tenant . '|' . $structure][] = $person;
        $this->establishments[$tenant . '|' . $establishment][] = $person;
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        return null;
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return [];
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return $this->structures[$tenantId . '|' . $structureId] ?? [];
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return $this->establishments[$tenantId . '|' . $establishmentId] ?? [];
    }
}
