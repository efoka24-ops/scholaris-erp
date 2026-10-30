<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

/** Classes (structures pédagogiques) où un enseignant a cours selon l'emploi du temps. Fourni par le module Scheduling (S07). */
interface TeacherClasses
{
    /**
     * @param string $onDate jour AAAA-MM-JJ : seuls les créneaux en vigueur ce jour comptent
     * @return list<string> identifiants des structures où l'enseignant a au moins un créneau, tous établissements du client
     */
    public function structureIdsOf(string $tenantId, string $teacherPersonId, string $onDate): array;
}
