<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Synchronisation par lot des appels enregistrés hors ligne. */
final class SyncRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['sessions' => Field::listOf(new SyncItemRequest(), 50)->required()];
    }
}
