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
