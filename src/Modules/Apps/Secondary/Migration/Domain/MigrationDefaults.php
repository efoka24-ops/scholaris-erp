<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Migration\Domain;

/**
 * Jeux de la reprise de données d'un ancien logiciel (T977) : colonnes des modèles de fichiers (CSV « ; » UTF-8 ou XLSX, première
 * feuille, en-têtes normalisés sans accents), colonnes obligatoires, lignes d'EXEMPLE fictives (refusées si elles restent dans le
 * fichier), taille d'un lot. Le format n'est pas officiel : c'est le modèle de la plateforme, téléchargeable depuis l'écran.
 *
 * Ordre conseillé : classes, puis élèves (fiche, garant, inscription dans la classe, matricule), puis soldes d'ouverture.
 */
final class MigrationDefaults
{
    public const CLASSES = 'classes';
    public const STUDENTS = 'students';
    public const BALANCES = 'balances';
    public const KINDS = [self::CLASSES, self::STUDENTS, self::BALANCES];

    public const LABELS = [
        self::CLASSES => 'Classes de l\'année',
        self::STUDENTS => 'Élèves et inscriptions',
        self::BALANCES => 'Soldes de frais d\'ouverture',
    ];

    /** @var array<string, list<string>> */
    public const COLUMNS = [
        self::CLASSES => ['classe', 'niveau', 'serie', 'capacite'],
        self::STUDENTS => ['id_ancien', 'nom', 'prenoms', 'date_naissance', 'sexe', 'lieu_naissance', 'classe', 'matricule', 'redoublant', 'nom_parent', 'prenoms_parent', 'telephone_parent', 'lien_parent'],
        self::BALANCES => ['id_ancien', 'matricule', 'code_frais', 'montant', 'echeance', 'libelle'],
    ];

    /** @var array<string, list<string>> */
    public const REQUIRED = [
        self::CLASSES => ['classe', 'niveau'],
        self::STUDENTS => ['nom', 'prenoms', 'date_naissance', 'classe'],
        self::BALANCES => ['code_frais', 'montant', 'echeance'],
    ];

    /** @var array<string, list<list<string>>> */
    public const SAMPLES = [
        self::CLASSES => [['EXEMPLE 6e A', '6E', '', '60'], ['EXEMPLE 2nde C', '2NDE', 'C', '55']],
        self::STUDENTS => [
            ['EXEMPLE-1', 'EXEMPLE', 'Aïcha', '2014-03-12', 'F', 'Garoua', '6e A', '', 'non', 'EXEMPLE', 'Moussa', '+237600000000', 'parent'],
            ['EXEMPLE-2', 'EXEMPLE', 'Brice', '13/07/2013', 'M', 'Douala', '6e A', '', 'oui', 'EXEMPLE', 'Jeanne', '+237600000001', 'guardian'],
        ],
        self::BALANCES => [['EXEMPLE-1', '', 'EXEMPLE', '25000', '2026-11-30', 'Reste de pension 2025-2026 (reprise)']],
    ];

    /** Lignes au plus par lot (au-delà : découper le fichier). */
    public const MAX_ROWS = 1000;

    /** Taille au plus d'un fichier joint, en octets (8 Mo). */
    public const MAX_BYTES = 8388608;

    /** Montant au plus d'une ligne de solde, en XAF. */
    public const MAX_AMOUNT_XAF = 100000000;

    private function __construct()
    {
    }

    public static function exists(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }
}
