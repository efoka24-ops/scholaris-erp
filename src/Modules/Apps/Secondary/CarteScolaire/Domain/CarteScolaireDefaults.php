<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Domain;

/**
 * Valeurs de départ des échanges par fichiers avec cartescolaire.cm (Q1), TOUTES « à confirmer » : le site du MINESEC ne publie ni
 * interface ni format d'export (recherche-cartescolaire-series-prix.md §2). Chaque valeur est surchargée par un paramètre
 * `secondary.carte_scolaire.*` (plateforme, client ou établissement) sans développement.
 */
final class CarteScolaireDefaults
{
    public const CONFIRMATION = 'to_confirm';

    /** Format du matricule (`secondary.carte_scolaire.national_id_pattern`, expression sans délimiteurs, majuscules) : volontairement large. */
    public const NATIONAL_ID_PATTERN = '[A-Z0-9][A-Z0-9-]{5,29}';

    /** Séparateur des fichiers CSV UTF-8 (`secondary.carte_scolaire.csv_delimiter`). */
    public const DELIMITER = ';';

    /** Colonnes du fichier des matricules (`secondary.carte_scolaire.columns.national_ids`) : champ => en-tête. */
    public const COLUMNS_NATIONAL_IDS = [
        'national_id' => 'matricule',
        'last_name' => 'nom',
        'first_names' => 'prenoms',
        'birth_date' => 'date_naissance',
        'class_label' => 'classe',
    ];

    /** Colonnes du relevé des paiements de l'économe (`secondary.carte_scolaire.columns.payments`). */
    public const COLUMNS_PAYMENTS = [
        'external_ref' => 'reference',
        'national_id' => 'matricule',
        'payment_kind' => 'nature',
        'amount' => 'montant',
        'paid_on' => 'date',
        'exam_code' => 'examen',
    ];

    /** Libellés de nature du relevé => nature interne (`secondary.carte_scolaire.payment_kinds`), comparés en minuscules sans accents. */
    public const PAYMENT_KINDS = [
        'contribution' => 'contribution',
        'contributions' => 'contribution',
        'contributions exigibles' => 'contribution',
        'frais de scolarite' => 'contribution',
        'exam_fee' => 'exam_fee',
        'examen' => 'exam_fee',
        'frais d\'examen' => 'exam_fee',
        'frais examen' => 'exam_fee',
    ];

    /** Niveaux d'entrée dont l'établissement génère le matricule (`secondary.carte_scolaire.entry_levels`), comparés normalisés. */
    public const ENTRY_LEVELS = ['6E', '6EME', 'SIXIEME', 'FORM 1', 'FORM ONE', '1RE ANNEE', '1ERE ANNEE'];

    /** Taille maximale d'un fichier importé (octets) et nombre de lignes rejetées gardées dans le rapport. */
    public const MAX_BYTES = 2000000;
    public const MAX_REPORTED_ROWS = 500;

    private function __construct()
    {
    }
}
