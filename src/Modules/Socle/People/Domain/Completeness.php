<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

/** Taux de complétude du dossier (« Dossier 72 % complet ») : identité, parents et garant, filière, pièces, à poids égal. */
final class Completeness
{
    private const IDENTITY_FIELDS = ['first_name', 'last_name', 'birth_date', 'birth_place', 'gender', 'nationality', 'phone'];

    /** @param array<string, mixed> $person */
    public static function percent(array $person, bool $hasGuarantor, bool $hasStructure, int $documentsReceived, int $documentsRequired): int
    {
        $filled = 0;
        foreach (self::IDENTITY_FIELDS as $field) {
            if (isset($person[$field]) && $person[$field] !== '') {
                ++$filled;
            }
        }
        $documents = $documentsRequired > 0 ? min(1.0, $documentsReceived / $documentsRequired) : 1.0;
        $score = $filled / count(self::IDENTITY_FIELDS) + ($hasGuarantor ? 1 : 0) + ($hasStructure ? 1 : 0) + $documents;

        return (int) round($score / 4 * 100);
    }
}
