<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Scheduling\Application;

use RuntimeException;
use Scholaris\Modules\Socle\Scheduling\Domain\Conflict;

/** Enregistrement refusé : l'emplacement demandé entre en conflit (traduit en 409 avec la liste des conflits). */
final class SchedulingConflict extends RuntimeException
{
    /** @param non-empty-list<Conflict> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('Conflit d\'emploi du temps : ' . $conflicts[0]->message);
    }
}
