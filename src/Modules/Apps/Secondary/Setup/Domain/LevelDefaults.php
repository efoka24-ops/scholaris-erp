<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Domain;

/**
 * Niveaux d'EXEMPLE proposés à un établissement qui n'a encore rien saisi (Q23 : noms des niveaux à confirmer par le ministère).
 * Ce ne sont pas des valeurs officielles : chaque niveau chargé porte l'origine `example`, il se renomme, se désactive ou se
 * complète librement. Filtrés par le profil (sections, enseignements, cycles) au chargement. Aucun examen n'est rattaché par
 * défaut (liste des examens tenue par la plateforme, Q3).
 */
final class LevelDefaults
{
    /** @var array<string, array<string, list<array{code: string, label: string, cycle: string}>>> section => enseignement => niveaux */
    public const EXAMPLE = [
        'fr' => [
            'general' => [
                ['code' => '6E', 'label' => 'Sixième', 'cycle' => 'first'],
                ['code' => '5E', 'label' => 'Cinquième', 'cycle' => 'first'],
                ['code' => '4E', 'label' => 'Quatrième', 'cycle' => 'first'],
                ['code' => '3E', 'label' => 'Troisième', 'cycle' => 'first'],
                ['code' => '2NDE', 'label' => 'Seconde', 'cycle' => 'second'],
                ['code' => '1ERE', 'label' => 'Première', 'cycle' => 'second'],
                ['code' => 'TLE', 'label' => 'Terminale', 'cycle' => 'second'],
            ],
            'technical' => [
                ['code' => '1A', 'label' => '1re année', 'cycle' => 'first'],
                ['code' => '2A', 'label' => '2e année', 'cycle' => 'first'],
                ['code' => '3A', 'label' => '3e année', 'cycle' => 'first'],
                ['code' => '4A', 'label' => '4e année', 'cycle' => 'first'],
                ['code' => '2NDE', 'label' => 'Seconde technique', 'cycle' => 'second'],
                ['code' => '1ERE', 'label' => 'Première technique', 'cycle' => 'second'],
                ['code' => 'TLE', 'label' => 'Terminale technique', 'cycle' => 'second'],
            ],
        ],
        'en' => [
            'general' => [
                ['code' => 'F1', 'label' => 'Form 1', 'cycle' => 'first'],
                ['code' => 'F2', 'label' => 'Form 2', 'cycle' => 'first'],
                ['code' => 'F3', 'label' => 'Form 3', 'cycle' => 'first'],
                ['code' => 'F4', 'label' => 'Form 4', 'cycle' => 'first'],
                ['code' => 'F5', 'label' => 'Form 5', 'cycle' => 'first'],
                ['code' => 'LS', 'label' => 'Lower Sixth', 'cycle' => 'second'],
                ['code' => 'US', 'label' => 'Upper Sixth', 'cycle' => 'second'],
            ],
            'technical' => [
                ['code' => 'F1', 'label' => 'Form 1 (technical)', 'cycle' => 'first'],
                ['code' => 'F2', 'label' => 'Form 2 (technical)', 'cycle' => 'first'],
                ['code' => 'F3', 'label' => 'Form 3 (technical)', 'cycle' => 'first'],
                ['code' => 'F4', 'label' => 'Form 4 (technical)', 'cycle' => 'first'],
                ['code' => 'F5', 'label' => 'Form 5 (technical)', 'cycle' => 'first'],
                ['code' => 'LS', 'label' => 'Lower Sixth (technical)', 'cycle' => 'second'],
                ['code' => 'US', 'label' => 'Upper Sixth (technical)', 'cycle' => 'second'],
            ],
        ],
    ];

    private function __construct()
    {
    }
}
