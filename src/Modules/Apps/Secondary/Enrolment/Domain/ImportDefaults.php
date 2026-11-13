<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Enrolment\Domain;

/**
 * Colonnes du fichier d'import des élèves (CSV « ; » UTF-8 ou XLSX, comme les autres applications) et taille d'un lot. Le format
 * n'est pas officiel : c'est le modèle de la plateforme (téléchargeable), en-têtes normalisés sans accents.
 */
final class ImportDefaults
{
    public const ALL = ['nom', 'prenoms', 'date_naissance', 'sexe', 'lieu_naissance', 'classe', 'matricule', 'redoublant', 'nom_parent', 'prenoms_parent', 'telephone_parent', 'lien_parent'];
    public const REQUIRED = ['nom', 'prenoms', 'date_naissance', 'classe'];
    public const MAX_ROWS = 1000;

    /** Deux lignes d'EXEMPLE fictives du modèle : refusées si elles restent dans le fichier. */
    public const SAMPLE = [
        ['EXEMPLE', 'Aïcha', '2014-03-12', 'F', 'Garoua', '6e A', '', 'non', 'EXEMPLE', 'Moussa', '+237600000000', 'parent'],
        ['EXEMPLE', 'Brice', '13/07/2013', 'M', 'Douala', '6e A', '', 'oui', 'EXEMPLE', 'Jeanne', '+237600000001', 'guardian'],
    ];

    private function __construct()
    {
    }
}
