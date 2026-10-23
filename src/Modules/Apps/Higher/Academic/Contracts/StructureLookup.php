<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Contracts;

/**
 * Recherche des écoles, départements et filières par leur CODE (reprise de données, fichiers saisis par l'établissement).
 * Les codes de filière et de département ne sont uniques que dans une école : le résultat est une liste, vide si inconnu,
 * de plusieurs éléments si le code est ambigu. Borné au client et à l'établissement ; aucun contrôle de périmètre.
 */
interface StructureLookup
{
    /** @return list<ProgramInfo> filières portant ce code (dans toutes les écoles, ou dans l'école de code donné) */
    public function programsByCode(string $tenantId, string $establishmentId, string $code, ?string $schoolCode = null): array;

    /** @return list<array{id: string, school_id: string, code: string, name: string}> départements portant ce code */
    public function departmentsByCode(string $tenantId, string $establishmentId, string $code, ?string $schoolCode = null): array;
}
