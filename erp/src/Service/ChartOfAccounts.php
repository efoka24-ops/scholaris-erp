<?php

declare(strict_types=1);

namespace Scholaris\Service;

/**
 * Plan comptable SYSCOHADA revise, reduit a ce qu'un etablissement scolaire
 * utilise reellement.
 *
 * Le plan complet compte plusieurs centaines de comptes, dont l'immense
 * majorite ne concerne pas une ecole. En proposer une trentaine, choisis pour
 * le secteur, rend le module utilisable des l'installation ; un comptable
 * ajoute ensuite ses propres comptes, et rien ici ne l'en empeche.
 *
 * Les classes suivent la norme : 1 capitaux, 2 immobilisations, 3 stocks,
 * 4 tiers, 5 tresorerie, 6 charges, 7 produits.
 */
final class ChartOfAccounts
{
    /**
     * @return list<array{code: string, name: string, class: int, nature: string}>
     */
    public static function syscohada(): array
    {
        return [
            // Classe 1 : ressources durables.
            ['code' => '101000', 'name' => 'Capital / Fonds de dotation', 'class' => 1, 'nature' => 'BILAN'],
            ['code' => '110000', 'name' => 'Report a nouveau', 'class' => 1, 'nature' => 'BILAN'],
            ['code' => '120000', 'name' => 'Resultat de l exercice', 'class' => 1, 'nature' => 'BILAN'],
            ['code' => '162000', 'name' => 'Emprunts bancaires', 'class' => 1, 'nature' => 'BILAN'],

            // Classe 2 : ce que l etablissement possede durablement.
            ['code' => '221000', 'name' => 'Terrains', 'class' => 2, 'nature' => 'BILAN'],
            ['code' => '231000', 'name' => 'Batiments scolaires', 'class' => 2, 'nature' => 'BILAN'],
            ['code' => '244000', 'name' => 'Materiel et mobilier scolaire', 'class' => 2, 'nature' => 'BILAN'],
            ['code' => '245000', 'name' => 'Materiel de transport', 'class' => 2, 'nature' => 'BILAN'],
            ['code' => '247000', 'name' => 'Materiel informatique', 'class' => 2, 'nature' => 'BILAN'],
            ['code' => '281000', 'name' => 'Amortissements des immobilisations', 'class' => 2, 'nature' => 'BILAN'],

            // Classe 3 : fournitures detenues.
            ['code' => '331000', 'name' => 'Fournitures scolaires en stock', 'class' => 3, 'nature' => 'BILAN'],
            ['code' => '332000', 'name' => 'Denrees de cantine en stock', 'class' => 3, 'nature' => 'BILAN'],
            ['code' => '338000', 'name' => 'Produits d entretien en stock', 'class' => 3, 'nature' => 'BILAN'],

            // Classe 4 : tiers. Le compte 411 porte les familles debitrices.
            ['code' => '401000', 'name' => 'Fournisseurs', 'class' => 4, 'nature' => 'BILAN'],
            ['code' => '411000', 'name' => 'Eleves et familles - frais de scolarite', 'class' => 4, 'nature' => 'BILAN'],
            ['code' => '419000', 'name' => 'Avances recues des familles', 'class' => 4, 'nature' => 'BILAN'],
            ['code' => '421000', 'name' => 'Personnel - remunerations dues', 'class' => 4, 'nature' => 'BILAN'],
            ['code' => '431000', 'name' => 'CNPS - cotisations sociales', 'class' => 4, 'nature' => 'BILAN'],
            ['code' => '441000', 'name' => 'Etat - impots sur salaires (IRPP)', 'class' => 4, 'nature' => 'BILAN'],
            ['code' => '443000', 'name' => 'Etat - TVA', 'class' => 4, 'nature' => 'BILAN'],

            // Classe 5 : tresorerie.
            ['code' => '521000', 'name' => 'Banque', 'class' => 5, 'nature' => 'BILAN'],
            ['code' => '531000', 'name' => 'Mobile Money', 'class' => 5, 'nature' => 'BILAN'],
            ['code' => '571000', 'name' => 'Caisse', 'class' => 5, 'nature' => 'BILAN'],

            // Classe 6 : charges.
            ['code' => '601000', 'name' => 'Achats de fournitures scolaires', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '602000', 'name' => 'Achats de denrees pour la cantine', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '605000', 'name' => 'Eau et electricite', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '622000', 'name' => 'Locations', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '624000', 'name' => 'Entretien et reparations', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '625000', 'name' => 'Assurances', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '628000', 'name' => 'Telephone et internet', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '641000', 'name' => 'Salaires du personnel', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '645000', 'name' => 'Charges sociales patronales', 'class' => 6, 'nature' => 'GESTION'],
            ['code' => '681000', 'name' => 'Dotations aux amortissements', 'class' => 6, 'nature' => 'GESTION'],

            // Classe 7 : produits.
            ['code' => '701000', 'name' => 'Frais de scolarite', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '702000', 'name' => 'Frais d inscription', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '703000', 'name' => 'Recettes de cantine', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '704000', 'name' => 'Recettes de transport scolaire', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '705000', 'name' => 'Frais d internat', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '706000', 'name' => 'Frais d examen', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '741000', 'name' => 'Subventions d exploitation', 'class' => 7, 'nature' => 'GESTION'],
            ['code' => '758000', 'name' => 'Dons et produits divers', 'class' => 7, 'nature' => 'GESTION'],
        ];
    }

    /** Libelle d'une classe comptable, pour les regroupements a l'ecran. */
    public static function className(int $class): string
    {
        return match ($class) {
            1 => 'Ressources durables',
            2 => 'Immobilisations',
            3 => 'Stocks',
            4 => 'Tiers',
            5 => 'Tresorerie',
            6 => 'Charges',
            7 => 'Produits',
            8 => 'Autres charges et produits',
            default => 'Comptes analytiques',
        };
    }

    /** Libelle d'un journal. */
    public static function journalName(string $code): string
    {
        return match ($code) {
            'AC' => 'Achats',
            'VE' => 'Ventes',
            'BQ' => 'Banque',
            'CA' => 'Caisse',
            default => 'Operations diverses',
        };
    }
}
