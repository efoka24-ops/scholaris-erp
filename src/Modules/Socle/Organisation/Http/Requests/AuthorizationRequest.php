<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Requests;

final class AuthorizationRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'structure_id' => Field::reference()->required(),
            'reference' => Field::string(80)->required(),
            'authority' => Field::string(160)->nullable(),
            'valid_from' => Field::date()->required(),
            'valid_to' => Field::date()->required(),
            // D2 : niveaux couverts (structures de niveau ; absent ou null = tous) et pièce numérisée.
            'covered_levels' => Field::custom(static function (mixed $value): array {
                if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
                    throw new \InvalidArgumentException('Ce champ doit être une liste de 50 niveaux au plus.');
                }

                return array_values(array_unique(array_map(static fn (mixed $item): string => Field::reference()->parse($item), $value)));
            })->nullable(),
            'document_id' => Field::reference()->nullable(),
        ];
    }

    protected function crossCheck(array $values, array $errors): array
    {
        if (isset($values['valid_from'], $values['valid_to']) && $values['valid_to'] < $values['valid_from']) {
            $errors['valid_to'] = ['La fin de validité ne peut pas précéder le début.'];
        }

        return $errors;
    }
}
