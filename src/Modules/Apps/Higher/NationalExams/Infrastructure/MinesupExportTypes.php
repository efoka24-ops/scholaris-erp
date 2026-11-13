<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\NationalExams\Infrastructure;

use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;

/**
 * Types d'export de la liste nationale (S14), enregistrés sous `integrations.export_types.higher_nationalexams`. Le gabarit
 * MINESUP exact n'a pas été fourni (question ouverte) : colonnes par défaut « à confirmer », remplaçables sans code par les
 * paramètres `higher.minesup.export.bts.columns` et `higher.minesup.export.hnd.columns`.
 */
final class MinesupExportTypes
{
    public const FIELDS = ['candidate_number', 'matricule', 'last_name', 'first_names', 'gender', 'specialty', 'exam_type', 'academic_year',
        'presence_percent', 'fee_receipt', 'exam_centre', 'watermark'];

    private const COLUMNS = [
        'N° candidat' => 'candidate_number', 'Matricule' => 'matricule', 'Nom' => 'last_name', 'Prénoms' => 'first_names', 'Sexe' => 'gender',
        'Spécialité' => 'specialty', 'Examen' => 'exam_type', 'Année académique' => 'academic_year', 'Centre' => 'exam_centre', 'Filigrane' => 'watermark',
    ];

    private function __construct()
    {
    }

    /** @return list<ConfiguredExportType> */
    public static function types(): array
    {
        return [
            new ConfiguredExportType('minesup.bts', 'Liste MINESUP examen national (à confirmer)', self::FIELDS, self::COLUMNS, 'higher.minesup.export.bts.columns'),
            new ConfiguredExportType('minesup.hnd', 'Liste MINESUP examen national (à confirmer)', self::FIELDS, self::COLUMNS, 'higher.minesup.export.hnd.columns'),
        ];
    }
}
