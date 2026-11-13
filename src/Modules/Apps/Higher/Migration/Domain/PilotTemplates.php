<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Domain;

/**
 * Gabarits de démarrage des instituts pilotes (commande higher:pilot-setup). Les intitulés des formations viennent d'informations
 * publiques et n'ont VALEUR DE GABARIT que : régime, durée et niveaux sont à confirmer par l'établissement, filières créées « fermées »
 * et suffixées « [à confirmer] ». AUCUNE autorisation MINESUP n'est créée : tant qu'une autorisation réelle n'est pas saisie, aucune
 * filière n'est inscriptible (RG-ACA-1).
 */
final class PilotTemplates
{
    public const SUFFIX = ' [à confirmer]';

    /**
     * @var array<string, array{label: string, schools: list<array{code: string, name: string, department: array{code: string, name: string},
     *      programs: list<array{code: string, name: string, regime: string, years: int}>}>}>
     */
    private const MODELS = [
        'sante' => [
            'label' => 'Santé et paramédical',
            'schools' => [[
                'code' => 'SANTE', 'name' => 'École de santé et paramédical', 'department' => ['code' => 'PARAMED', 'name' => 'Département santé et paramédical'],
                'programs' => [
                    ['code' => 'EMS-ODONTO', 'name' => 'BTS Études médico-sanitaires, option odontostomatologie', 'regime' => 'bts', 'years' => 2],
                    ['code' => 'EMS-SF', 'name' => 'BTS Études médico-sanitaires, option sage-femme', 'regime' => 'bts', 'years' => 2],
                    ['code' => 'STB-TP', 'name' => 'BTS Sciences et techniques biomédicales, option techniques pharmaceutiques', 'regime' => 'bts', 'years' => 2],
                    ['code' => 'DELMED', 'name' => 'Délégué médical', 'regime' => 'certificate', 'years' => 1],
                    ['code' => 'VENDPHARMA', 'name' => 'Vendeur en pharmacie', 'regime' => 'certificate', 'years' => 1],
                    ['code' => 'AVS', 'name' => 'Auxiliaire de vie sociale', 'regime' => 'certificate', 'years' => 1],
                    ['code' => 'AIDESOIGN', 'name' => 'Aide-soignant', 'regime' => 'certificate', 'years' => 1],
                ],
            ]],
        ],
        'gestion' => [
            'label' => 'Gestion et administration',
            'schools' => [[
                'code' => 'GESTION', 'name' => 'École de gestion et d\'administration', 'department' => ['code' => 'GESTADM', 'name' => 'Département gestion et administration'],
                'programs' => [
                    ['code' => 'BTS-GAM', 'name' => 'BTS Gestion, option assistant manager', 'regime' => 'bts', 'years' => 2],
                    ['code' => 'BTS-GCT', 'name' => 'BTS Gestion des collectivités territoriales', 'regime' => 'bts', 'years' => 2],
                    ['code' => 'GRH', 'name' => 'Gestion des ressources humaines', 'regime' => 'certificate', 'years' => 1],
                    ['code' => 'ACTD', 'name' => 'Administration des collectivités territoriales décentralisées', 'regime' => 'certificate', 'years' => 1],
                ],
            ]],
        ],
        // Centre de formation professionnelle agréé MINEFOP : spécialités d'un agrément public (12 ou 24 mois, alternance obligatoire,
        // cycle de 24 mois au plus). Durée posée à 2 ans par défaut : à confirmer filière par filière d'après l'agrément.
        'formation-pro' => [
            'label' => 'Formation professionnelle : agropastoral, énergie solaire, numérique et gestion',
            'schools' => [
                [
                    'code' => 'AGRO', 'name' => 'Pôle agropastoral et agroécologie', 'department' => ['code' => 'AGROPAST', 'name' => 'Département agropastoral'],
                    'programs' => [
                        ['code' => 'AGRI', 'name' => 'Agriculture', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'ELEV', 'name' => 'Élevage', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'AVIC', 'name' => 'Aviculture', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'PISC', 'name' => 'Pisciculture', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'TRLAIT', 'name' => 'Transformation du lait', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'CEREAL', 'name' => 'Production des céréales', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'BOVPR', 'name' => 'Élevage de bovins et petits ruminants', 'regime' => 'certificate', 'years' => 2],
                    ],
                ],
                [
                    'code' => 'ENERGIE', 'name' => 'Pôle énergie solaire', 'department' => ['code' => 'SOLAIRE', 'name' => 'Département énergie solaire'],
                    'programs' => [
                        ['code' => 'MAINTSOL', 'name' => 'Maintenance des systèmes solaires', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'INSTPV', 'name' => 'Installation des systèmes photovoltaïques', 'regime' => 'certificate', 'years' => 2],
                    ],
                ],
                [
                    'code' => 'NUMBUR', 'name' => 'Pôle numérique et bureautique', 'department' => ['code' => 'NUMERIQUE', 'name' => 'Département numérique et bureautique'],
                    'programs' => [
                        ['code' => 'SECCPT', 'name' => 'Secrétariat comptable', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'CPTINFO', 'name' => 'Comptabilité informatisée et gestion', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'DEVWEB', 'name' => 'Développement web', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'INFOIND', 'name' => 'Informatique industrielle', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'SECDIR', 'name' => 'Secrétariat de direction', 'regime' => 'certificate', 'years' => 2],
                    ],
                ],
                [
                    'code' => 'MGTDEV', 'name' => 'Pôle management et développement', 'department' => ['code' => 'MANAGEMENT', 'name' => 'Département management et développement'],
                    'programs' => [
                        ['code' => 'IDLD', 'name' => 'Ingénierie du développement local et de la décentralisation', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'MPP', 'name' => 'Management des projets et programmes', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'GONG', 'name' => 'Gestion des ONG', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'LOGTR', 'name' => 'Logistique et transport', 'regime' => 'certificate', 'years' => 2],
                        ['code' => 'GRH', 'name' => 'Gestion des ressources humaines', 'regime' => 'certificate', 'years' => 2],
                    ],
                ],
            ],
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function names(): array
    {
        return [...array_keys(self::MODELS), 'complet'];
    }

    /**
     * @return array{label: string, schools: list<array{code: string, name: string, department: array{code: string, name: string},
     *     programs: list<array{code: string, name: string, regime: string, years: int}>}>}|null
     */
    public static function get(string $name): ?array
    {
        if ($name === 'complet') {
            return ['label' => 'Complexe : santé, paramédical, gestion et administration', 'schools' => [...self::MODELS['sante']['schools'], ...self::MODELS['gestion']['schools']]];
        }

        return self::MODELS[$name] ?? null;
    }
}
