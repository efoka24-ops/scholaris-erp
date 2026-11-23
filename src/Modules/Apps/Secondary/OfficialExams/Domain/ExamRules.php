<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\OfficialExams\Domain;

/** Codes disponibles par configuration et états de session/résultats ; aucune règle réglementaire n'est présumée. */
final class ExamRules
{
    public const SESSION_OPEN = 'open';
    public const SESSION_CLOSED = 'closed';

    private function __construct()
    {
    }
}
