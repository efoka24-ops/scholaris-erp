<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Primary\Tutelage\Domain;

use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;

/**
 * Exports de tutelle du primaire (EX-PRI-45 ; Q16, à confirmer : le format officiel MINEDUB n'est pas publié) : types S14 enregistrés
 * sous `integrations.export_types.primary`. Les colonnes se règlent sans code par `primary.tutelage.<type>.columns`.
 */
final class PrimaryExportTypes
{
    public const ENROLMENT = 'primary.enrolment';
    public const RESULTS = 'primary.results';
    public const CENSUS = 'primary.census';
    public const EXAM = 'primary.exam';

    /** @var array<string, list<string>> */
    public const FIELDS = [
        self::ENROLMENT => ['level', 'class', 'boys', 'girls', 'gender_unknown', 'total', 'repeaters', 'capacity', 'class_size_cap', 'over_cap'],
        self::RESULTS => ['level', 'class', 'period', 'learners', 'with_average', 'class_average', 'admitted', 'adjourned', 'cycle_end', 'pass_rate'],
        self::CENSUS => ['level', 'gender', 'age', 'count'],
        self::EXAM => ['exam', 'level', 'centre', 'sub_centre', 'registered', 'withdrawn', 'passed', 'failed', 'absent', 'pass_rate'],
    ];

    private function __construct()
    {
    }

    /** @return list<ConfiguredExportType> */
    public static function types(): array
    {
        return [
            new ConfiguredExportType(self::ENROLMENT, 'Effectifs par classe et par sexe', self::FIELDS[self::ENROLMENT], [
                'Niveau' => 'level', 'Classe' => 'class', 'Garçons' => 'boys', 'Filles' => 'girls', 'Sexe non renseigné' => 'gender_unknown', 'Total' => 'total',
                'Redoublants' => 'repeaters', 'Capacité' => 'capacity', 'Plafond' => 'class_size_cap', 'Au-delà du plafond' => 'over_cap',
            ], 'primary.tutelage.enrolment.columns'),
            new ConfiguredExportType(self::RESULTS, 'Résultats par classe et par trimestre', self::FIELDS[self::RESULTS], [
                'Niveau' => 'level', 'Classe' => 'class', 'Période' => 'period', 'Effectif' => 'learners', 'Élèves notés' => 'with_average',
                'Moyenne de classe' => 'class_average', 'Admis' => 'admitted', 'Redoublants' => 'adjourned', 'Fin de cycle' => 'cycle_end', 'Taux de passage (%)' => 'pass_rate',
            ], 'primary.tutelage.results.columns'),
            new ConfiguredExportType(self::CENSUS, 'Recensement par niveau, sexe et âge', self::FIELDS[self::CENSUS], [
                'Niveau' => 'level', 'Sexe' => 'gender', 'Âge' => 'age', 'Effectif' => 'count',
            ], 'primary.tutelage.census.columns'),
            new ConfiguredExportType(self::EXAM, 'Examen de fin de cycle', self::FIELDS[self::EXAM], [
                'Examen' => 'exam', 'Niveau' => 'level', 'Centre' => 'centre', 'Sous-centre' => 'sub_centre', 'Inscrits' => 'registered', 'Retirés' => 'withdrawn',
                'Admis' => 'passed', 'Ajournés' => 'failed', 'Absents' => 'absent', 'Taux de réussite (%)' => 'pass_rate',
            ], 'primary.tutelage.exam.columns'),
        ];
    }
}
