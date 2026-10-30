<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Contracts;

use RuntimeException;

/** Pose de créneau refusée par TimetableWriter : conflit d'enseignant, de salle, de groupe ou de capacité. */
final class TimetableConflict extends RuntimeException
{
    /** @param list<array{kind: string, message: string, slot_id: string|null, structure_ids: list<string>}> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Conflit d\'emploi du temps : ' . ($conflicts[0]['message'] ?? ''));
    }
}
