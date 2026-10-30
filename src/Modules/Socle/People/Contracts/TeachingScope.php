<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Périmètre d'un enseignant : un enseignant ne voit que les apprenants de ses classes.
 * La liaison réelle (emploi du temps, affectations) sera branchée dans definitions.php par le module qui la connaît ;
 * par défaut personne n'est rattaché à un enseignant (refus).
 */
interface TeachingScope
{
    /** @return list<string> identifiants des apprenants des classes de cet enseignant (personne) */
    public function learnerIdsOf(string $tenantId, string $teacherPersonId): array;
}
