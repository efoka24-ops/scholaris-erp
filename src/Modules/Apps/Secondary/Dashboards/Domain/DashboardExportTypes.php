<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Dashboards\Domain;

use Scholaris\Modules\Socle\Integrations\Contracts\ConfiguredExportType;

/**
 * Rapports exportables des tableaux de bord (CSV ou XLSX, fichier joint téléchargé), types S14 enregistrés sous
 * `integrations.export_types.secondary_dashboards`. Formats libres (Q12, à confirmer : aucun modèle officiel MINESEC publié) ; les
 * colonnes se règlent sans code par `secondary.dashboards.<rapport>.columns`. Le rapport réseau ne porte aucune donnée nominative.
 */
final class DashboardExportTypes
{
    /** @var array<string, array{key: string, label: string, columns: array<string, string>}> nom du rapport dans l'adresse => type */
    public const REPORTS = [
        'objectives' => ['key' => 'secondary.dashboards.objectives', 'label' => 'Objectifs O1 à O7', 'columns' => [
            'Objectif' => 'code', 'Libellé' => 'label', 'Disponible' => 'available', 'Valeur' => 'value', 'Unité' => 'unit', 'Cible' => 'target',
            'Sens' => 'direction', 'Atteint' => 'met', 'Motif' => 'reason', 'Année' => 'academic_year_id', 'Trimestre' => 'term_id',
        ]],
        'rentree-classes' => ['key' => 'secondary.dashboards.rentree_classes', 'label' => 'Rentrée : effectifs par classe', 'columns' => [
            'Niveau' => 'level', 'Classe' => 'class', 'Section' => 'section', 'Enseignement' => 'teaching', 'Filles' => 'female', 'Garçons' => 'male',
            'Sexe non renseigné' => 'gender_unknown', 'Total' => 'total', 'Redoublants' => 'repeaters', 'Capacité' => 'capacity',
        ]],
        'rentree-census' => ['key' => 'secondary.dashboards.rentree_census', 'label' => 'Rentrée : effectifs par niveau, sexe et âge', 'columns' => [
            'Niveau' => 'level', 'Sexe' => 'gender', 'Âge' => 'age_band', 'Effectif' => 'count',
        ]],
        'rentree-staff' => ['key' => 'secondary.dashboards.rentree_staff', 'label' => 'Rentrée : personnel par statut', 'columns' => [
            'Statut' => 'kind', 'Catégorie' => 'category', 'Enseigne' => 'teaches', 'Effectif' => 'count',
        ]],
        'annual-levels' => ['key' => 'secondary.dashboards.annual_levels', 'label' => 'Rapport annuel : résultats par niveau', 'columns' => [
            'Niveau' => 'level', 'Classes' => 'classes', 'Effectif' => 'students', 'Élèves notés' => 'with_average', 'Moyenne' => 'average',
            'Admis' => 'passed', 'Taux de réussite (%)' => 'pass_rate', 'Effectif N-1' => 'previous_students', 'Moyenne N-1' => 'previous_average',
            'Taux N-1 (%)' => 'previous_pass_rate',
        ]],
        'annual-exams' => ['key' => 'secondary.dashboards.annual_exams', 'label' => 'Rapport annuel : examens officiels', 'columns' => [
            'Examen' => 'exam', 'Code' => 'exam_code', 'Candidats' => 'candidates', 'Résultats' => 'results', 'Admis' => 'passed',
            'Taux de réussite (%)' => 'pass_rate', 'Candidats N-1' => 'previous_candidates', 'Taux N-1 (%)' => 'previous_pass_rate',
        ]],
        'network' => ['key' => 'secondary.dashboards.network', 'label' => 'Réseau : indicateurs agrégés', 'columns' => [
            'Établissement' => 'establishment', 'Année' => 'academic_year_id', 'Effectif' => 'students', 'Filles' => 'female', 'Garçons' => 'male',
            'Taux de réussite aux examens (%)' => 'exam_pass_rate', 'Taux de recouvrement (%)' => 'recovery_rate', 'Cases masquées' => 'masked',
        ]],
    ];

    private function __construct()
    {
    }

    /** @return list<ConfiguredExportType> */
    public static function types(): array
    {
        $types = [];
        foreach (self::REPORTS as $name => $report) {
            $types[] = new ConfiguredExportType($report['key'], $report['label'], array_values($report['columns']), $report['columns'], 'secondary.dashboards.' . $name . '.columns');
        }

        return $types;
    }
}
