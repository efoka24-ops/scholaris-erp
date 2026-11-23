<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Academic\Domain;

/** Mode d'évaluation d'une matière dans un niveau (Q6, défaut « les deux »). */
final class EvaluationMode
{
    public const NUMERIC = 'numeric';
    public const COMPETENCY = 'competency';
    public const BOTH = 'both';
    public const ALL = [self::NUMERIC, self::COMPETENCY, self::BOTH];

    private function __construct()
    {
    }
}
