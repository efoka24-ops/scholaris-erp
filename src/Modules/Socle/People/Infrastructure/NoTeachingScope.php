<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Infrastructure;

use Scholaris\Modules\Socle\People\Contracts\TeachingScope;

/** Par défaut, aucun apprenant n'est rattaché à un enseignant : refus tant que le module des classes n'est pas branché. */
final class NoTeachingScope implements TeachingScope
{
    public function learnerIdsOf(string $tenantId, string $teacherPersonId): array
    {
        return [];
    }
}
