<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Http\Requests;

/** Signalement d'un incident par le surveillant. */
final class IncidentRequest extends ValidatedRequest
{
    protected function fields(): array
    {
        return [
            'person_id' => Field::id()->required(),
            'occurred_on' => Field::date()->required(),
            'category' => Field::custom(static function (mixed $value): string {
                if (!is_string($value) || preg_match('/^[a-z0-9_]{2,40}$/', $value) !== 1) {
                    throw new \InvalidArgumentException('La catégorie doit contenir 2 à 40 caractères parmi a-z, 0-9 et _.');
                }

                return $value;
            })->required(),
            'description' => Field::string(1000)->required(),
        ];
    }
}
