<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Dashboards\Application;

/** Une classe primaire de l'établissement avec les identifiants de ses élèves inscrits (usage interne, jamais renvoyé tel quel). */
final class ClassFigure
{
    /** @param list<string> $learnerIds */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $levelId,
        public readonly ?int $capacity,
        public readonly array $learnerIds,
    ) {
    }
}
