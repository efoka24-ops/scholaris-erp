<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/** Annuaire des inscriptions. Fourni par le module People (S03). */
interface EnrollmentDirectory
{
    public function find(string $tenantId, string $enrollmentId): ?EnrollmentInfo;

    /** @return list<EnrollmentInfo> */
    public function forPerson(string $tenantId, string $personId): array;

    /** @return list<string> identifiants des personnes inscrites (statut actif) dans cette structure */
    public function personIdsInStructure(string $tenantId, string $structureId): array;

    /** @return list<string> identifiants des personnes inscrites (statut actif) dans cet établissement */
    public function personIdsInEstablishment(string $tenantId, string $establishmentId): array;
}
