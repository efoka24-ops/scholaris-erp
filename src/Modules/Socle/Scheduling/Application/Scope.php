<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

/** Périmètre de lecture d'un appelant sans droit scheduling.read : son propre emploi du temps et celui de ses classes. */
final class Scope
{
    /** @param list<string> $structureIds classes ou groupes de l'appelant et de ses apprenants */
    public function __construct(public readonly string $personId, public readonly array $structureIds)
    {
    }

    /** @param list<string> $slotStructureIds */
    public function allows(string $teacherPersonId, array $slotStructureIds, ?string $originalTeacherPersonId = null): bool
    {
        return $teacherPersonId === $this->personId
            || $originalTeacherPersonId === $this->personId
            || array_intersect($slotStructureIds, $this->structureIds) !== [];
    }
}
