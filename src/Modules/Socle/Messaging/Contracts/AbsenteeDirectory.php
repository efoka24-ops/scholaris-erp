<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/**
 * Absents du jour, pour le ciblage « absents ». À fournir par le module Attendance (liaison dans son definitions.php).
 * Tant qu'il n'est pas lié, ce ciblage ne désigne personne.
 */
interface AbsenteeDirectory
{
    /** @return list<string> identifiants des apprenants absents ce jour-là (AAAA-MM-JJ) dans cet établissement */
    public function absentLearnerIds(string $tenantId, string $establishmentId, string $date): array;
}
