<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Infrastructure;

use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradeBook;
use Scholaris\Modules\Apps\Higher\Teaching\Contracts\GradingCompleteness;

/**
 * Fournisseur de RG-ENS-5 (solde des vacations retenu tant que la saisie des notes de l'EC est incomplète) : branche la saisie des notes
 * (Grades\Contracts\GradeBook::elementComplete) sur le point d'extension de l'enseignement, lu par la condition de payabilité IPES.
 */
final class GradeBookCompleteness implements GradingCompleteness
{
    public function __construct(private readonly GradeBook $grades)
    {
    }

    public function isComplete(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): bool
    {
        return $this->grades->elementComplete($tenantId, $establishmentId, $elementId, $groupId);
    }
}
