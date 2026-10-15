<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

use Scholaris\Modules\Socle\Organisation\Domain\PeriodKind;

final class PeriodRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'academic_year_id' => Field::reference()->required(),
            'kind' => Field::enum(array_map(static fn (PeriodKind $kind): string => $kind->value, PeriodKind::cases()))->required(),
            'ordinal' => Field::int(1, 12)->required(),
            'starts_on' => Field::date()->required(),
            'ends_on' => Field::date()->required(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        if (isset($values['starts_on'], $values['ends_on']) && $values['ends_on'] < $values['starts_on']) {
            $errors['ends_on'] = ['La date de fin ne peut pas précéder la date de début.'];
        }

        return $errors;
    }
}
