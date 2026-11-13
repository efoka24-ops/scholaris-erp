<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Contracts;

/** Annuaire du personnel. Fourni par le module Staff (S10). */
interface StaffDirectory
{
    public function find(string $tenantId, string $personId): ?StaffInfo;

    /** @return list<string> identifiants des enseignants actifs affectés à cet établissement */
    public function teacherIdsIn(string $tenantId, string $establishmentId): array;
}
