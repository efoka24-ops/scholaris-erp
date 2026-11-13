<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Domain;

/** En-têtes français des colonnes de tableau pour les exports CSV et XLSX (l'écran traduit lui-même ces clés). */
final class Columns
{
    public const LABELS = [
        'school' => 'École', 'department' => 'Département', 'program' => 'Filière', 'level' => 'Niveau', 'group' => 'Groupe',
        'applications' => 'Candidatures', 'admitted' => 'Admis', 'admission_rate' => 'Taux d\'admission (%)', 'enrolled' => 'Inscrits',
        'accounts' => 'Comptes', 'expected_xaf' => 'Attendu (XAF)', 'collected_xaf' => 'Encaissé (XAF)', 'outstanding_xaf' => 'Reste dû (XAF)',
        'overdue_xaf' => 'Impayés échus (XAF)', 'overdue_accounts' => 'Comptes en impayé', 'recovery_rate' => 'Recouvrement (%)',
        'channel' => 'Canal', 'payments' => 'Paiements', 'amount_xaf' => 'Montant (XAF)',
        'element_code' => 'Code EC', 'element_title' => 'Élément constitutif', 'assessments' => 'Évaluations', 'draft' => 'Brouillon',
        'submitted' => 'Soumises', 'validated' => 'Validées', 'locked' => 'Verrouillées', 'published' => 'Publiées', 'overdue' => 'En retard',
        'entry_rate' => 'Saisie (%)', 'students' => 'Étudiants notés', 'passed' => 'Réussites', 'success_rate' => 'Réussite (%)',
        'unit' => 'UE', 'elements' => 'EC', 'label' => 'Séance', 'sessions' => 'Séances', 'records' => 'Appels', 'present' => 'Présents',
        'late' => 'Retards', 'absent' => 'Absents', 'absence_rate' => 'Absences (%)', 'unjustified_rate' => 'Non justifiées (%)',
        'month' => 'Mois', 'statements' => 'Relevés', 'hours' => 'Heures', 'hetd' => 'HETD', 'gross_xaf' => 'Brut (XAF)', 'net_xaf' => 'Net (XAF)',
        'paid_xaf' => 'Payé (XAF)', 'exam_type' => 'Examen', 'academic_year_id' => 'Année', 'candidates' => 'Candidats', 'resulted' => 'Résultats',
        'failed' => 'Ajournés', 'pass_rate' => 'Admission (%)', 'sex' => 'Sexe', 'age_band' => 'Âge', 'male' => 'Hommes', 'female' => 'Femmes',
        'unknown' => 'Non renseigné',
    ];

    private function __construct()
    {
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}
