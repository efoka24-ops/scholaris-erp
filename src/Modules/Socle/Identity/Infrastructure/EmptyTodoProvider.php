<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\TodoProvider;

/** Version minimale : aucune tâche tant qu'aucun module n'en fournit. */
final class EmptyTodoProvider implements TodoProvider
{
    public function forUser(string $tenantId, string $userId): array
    {
        return [];
    }
}
