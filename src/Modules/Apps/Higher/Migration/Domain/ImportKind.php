<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Domain;

/**
 * Jeux de données repris (lot I4) : colonnes des fichiers CSV (UTF-8, séparateur « ; ») et XLSX, colonnes obligatoires et lignes
 * d'exemple du modèle téléchargeable. Tous les exemples sont FICTIFS. Pas de reprise d'années antérieures, sauf les UE capitalisées.
 */
final class ImportKind
{
    public const STUDENTS = 'students';
    public const CREDITS = 'credits';
    public const BALANCES = 'balances';
    public const STAFF = 'staff';
    public const MARKS = 'marks';

    public const MAX_ROWS = 5000;

    /**
     * @var array<string, array{label: string, columns: list<string>, required: list<string>, sample: list<list<string>>}>
     */
    private const SPEC = [
        self::STUDENTS => [
            'label' => 'Étudiants en cours et inscription',
            'columns' => ['matricule', 'nom', 'prenom', 'date_naissance', 'lieu_naissance', 'sexe', 'telephone', 'email', 'filiere', 'niveau', 'groupe', 'mode', 'redoublant', 'person_id', 'confirmer_different'],
            'required' => ['nom', 'prenom', 'date_naissance', 'filiere', 'niveau'],
            'sample' => [
                ['26EX-GE-0001', 'Exemple', 'Awa', '2005-03-14', 'Garoua', 'F', '+237600000001', '', 'GE', 'L1', 'G1', 'jour', 'non', '', ''],
                ['', 'Modele', 'Issa', '2004-11-02', 'Maroua', 'M', '+237600000002', 'issa.modele@example.org', 'GE', 'L1', 'G1', 'jour', 'oui', '', ''],
            ],
        ],
        self::CREDITS => [
            'label' => 'UE capitalisées et dettes',
            'columns' => ['matricule', 'filiere', 'niveau', 'annee', 'ue_code', 'ue_intitule', 'credits', 'moyenne', 'statut'],
            'required' => ['matricule', 'filiere', 'niveau', 'annee', 'ue_code', 'credits', 'statut'],
            'sample' => [
                ['26EX-GE-0001', 'GE', 'L1', '2024-2025', 'UE101', 'Comptabilité générale', '6', '12,50', 'capitalisee'],
                ['26EX-GE-0001', 'GE', 'L1', '2024-2025', 'UE102', 'Mathématiques financières', '6', '', 'dette'],
            ],
        ],
        self::BALANCES => [
            'label' => 'Soldes d\'ouverture et échéanciers',
            'columns' => ['matricule', 'filiere', 'niveau', 'mode', 'solde_xaf', 'tranche1_date', 'tranche1_xaf', 'tranche2_date', 'tranche2_xaf', 'tranche3_date', 'tranche3_xaf'],
            'required' => ['matricule', 'filiere', 'niveau', 'solde_xaf', 'tranche1_date'],
            'sample' => [
                ['26EX-GE-0001', 'GE', 'L1', 'jour', '240000', '2026-11-15', '120000', '2027-01-15', '120000', '', ''],
                ['26EX-GE-0002', 'GE', 'L1', 'jour', '50000', '2026-11-30', '', '', '', '', ''],
            ],
        ],
        self::STAFF => [
            'label' => 'Personnel enseignant et affectations',
            'columns' => ['personne_id', 'departement', 'statut', 'grade', 'fonctionnaire', 'autorisation_ref', 'autorisation_document', 'autorisation_jusqua', 'filiere', 'niveau', 'groupe', 'ec_code', 'nature', 'heures'],
            'required' => ['personne_id', 'statut'],
            'sample' => [
                ['01EXEMPLEPERSONNE0000001', 'DGE', 'vacataire', 'Assistant', 'non', '', '', '', 'GE', 'L1', 'G1', 'CG', 'cm', '18'],
                ['01EXEMPLEPERSONNE0000001', 'DGE', 'vacataire', 'Assistant', 'non', '', '', '', 'GE', 'L1', 'G1', 'CG', 'td', '12'],
            ],
        ],
        self::MARKS => [
            'label' => 'Notes de l\'année en cours',
            'columns' => ['matricule', 'filiere', 'niveau', 'groupe', 'ec_code', 'evaluation', 'note'],
            'required' => ['matricule', 'filiere', 'niveau', 'ec_code', 'evaluation', 'note'],
            'sample' => [
                ['26EX-GE-0001', 'GE', 'L1', 'G1', 'CG', 'cc', '13,5'],
                ['26EX-GE-0002', 'GE', 'L1', 'G1', 'CG', 'cc', 'ABJ'],
            ],
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SPEC);
    }

    public static function exists(string $kind): bool
    {
        return isset(self::SPEC[$kind]);
    }

    public static function label(string $kind): string
    {
        return self::SPEC[$kind]['label'];
    }

    /** @return list<string> */
    public static function columns(string $kind): array
    {
        return self::SPEC[$kind]['columns'];
    }

    /** @return list<string> */
    public static function required(string $kind): array
    {
        return self::SPEC[$kind]['required'];
    }

    /** @return list<list<string>> */
    public static function sample(string $kind): array
    {
        return self::SPEC[$kind]['sample'];
    }
}
