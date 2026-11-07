<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

final class AcademicYearRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'label' => Field::string(40)->required(),
            'starts_on' => Field::date()->required(),
            'ends_on' => Field::date()->required(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        if (isset($values['starts_on'], $values['ends_on']) && $values['ends_on'] <= $values['starts_on']) {
            $errors['ends_on'] = ['La date de fin doit être postérieure à la date de début.'];
        }

        return $errors;
    }
}
