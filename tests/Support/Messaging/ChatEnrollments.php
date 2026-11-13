<?php

declare(strict_types=1);

namespace Tests\Support\Messaging;

use Scholaris\Modules\Socle\People\Contracts\EnrollmentDirectory;
use Scholaris\Modules\Socle\People\Contracts\EnrollmentInfo;

/** Doublure des inscriptions pour la messagerie interne (forPerson renseigné, contrairement à InMemoryEnrollments). */
final class ChatEnrollments implements EnrollmentDirectory
{
    /** @var array<string, list<EnrollmentInfo>> */
    private array $items = [];

    public function enrol(string $tenant, string $establishment, string $learnerId, string $status = EnrollmentInfo::ACTIVE): void
    {
        $this->items[$tenant . '|' . $learnerId][] = new EnrollmentInfo('en-' . $learnerId, $tenant, $learnerId, $establishment, 's1', 'y1', $status);
    }

    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo
    {
        return null;
    }

    public function forPerson(string $tenantId, string $personId): array
    {
        return $this->items[$tenantId . '|' . $personId] ?? [];
    }

    public function personIdsInStructure(string $tenantId, string $structureId): array
    {
        return [];
    }

    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array
    {
        return [];
    }
}
