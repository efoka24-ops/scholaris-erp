<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Tutelage\Infrastructure;

use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;

/**
 * Liste des inscrits transmise à l'université de tutelle (DOC-18, EX-TUT-01), type d'export S14 `tutelle.enrolled` enregistré
 * sous `integrations.export_types.higher_tutelage`. Le format de chaque université se règle sans code par le paramètre
 * `higher.tutelage.export.columns` (en-tête => champ).
 */
final class TutelageExportTypes
{
    public const KEY = 'tutelle.enrolled';
    public const FIELDS = ['matricule', 'last_name', 'first_names', 'gender', 'program_code', 'program_name', 'level_code', 'academic_year',
        'enrolment_status', 'attempt_number', 'university_name'];

    private function __construct()
    {
    }

    /** @return list<ConfiguredExportType> */
    public static function types(): array
    {
        return [new ConfiguredExportType(self::KEY, 'Liste des inscrits pour la tutelle', self::FIELDS, [
            'Matricule' => 'matricule', 'Nom' => 'last_name', 'Prénoms' => 'first_names', 'Sexe' => 'gender', 'Filière' => 'program_name',
            'Niveau' => 'level_code', 'Année académique' => 'academic_year', 'Inscription' => 'enrolment_status', 'Université de tutelle' => 'university_name',
        ], 'higher.tutelage.export.columns')];
    }
}
