<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Décision (validation ou refus) avec commentaire facultatif. */
final class DecisionRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return ['note' => Field::string(255)];
    }
}
