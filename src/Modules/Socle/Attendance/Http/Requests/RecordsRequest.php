<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Enregistrement des lignes d'un appel par lot. */
final class RecordsRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['records' => Field::listOf(new RecordLineRequest(), 500)->required()];
    }
}
