<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Contracts;

/** Profils de niveau d'un établissement, toujours bornés au client et à l'établissement. */
interface LevelDirectory
{
    public function profile(string $tenantId, string $establishmentId, string $levelStructureId): ?LevelProfile;

    /** @return list<LevelProfile> triés par ordre d'affichage */
    public function all(string $tenantId, string $establishmentId): array;
}
