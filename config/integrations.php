<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? $default;

/**
 * Exports fichier vers des organismes externes (aucune API n'est spécifiée à ce jour).
 * `columns` : en-tête écrit dans le fichier => champ source disponible (voir CartescolaireFileExporter::FIELDS et
 * TutelleFileExporter::FIELDS). Les valeurs ci-dessous sont GÉNÉRIQUES : à remplacer par la liste exacte exigée par l'organisme.
 */
return [
    'cartescolaire' => [
        'format' => (string) $env('CARTESCOLAIRE_FORMAT', 'csv'),
        'delimiter' => ';',
        'columns' => [
            'matricule' => 'internal_number',
            'nom' => 'last_name',
            'prenom' => 'first_name',
            'date_naissance' => 'birth_date',
            'classe' => 'structure_id',
            'annee' => 'academic_year_id',
            'etablissement' => 'establishment_name',
        ],
    ],
    'tutelle' => [
        'format' => (string) $env('TUTELLE_FORMAT', 'csv'),
        'delimiter' => ';',
        'columns' => [
            'etablissement' => 'establishment_name',
            'type' => 'establishment_type',
            'effectif_inscrits' => 'enrolled_learners',
            'enseignants' => 'teachers',
        ],
    ],
];
