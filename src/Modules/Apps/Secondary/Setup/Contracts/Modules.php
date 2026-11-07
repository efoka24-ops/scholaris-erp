<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Liste FERMÉE des modules activables (spec 005 §1.3). Le noyau (paramétrage, inscriptions, appel, notes, conseils, bulletins…)
 * n'est pas un module : il est toujours actif. Codes stables (stockés dans le profil d'établissement) : ne jamais renommer.
 */
final class Modules
{
    public const SECOND_CYCLE = 'second_cycle';
    public const ORIENTATION = 'orientation';
    public const OFFICIAL_EXAMS = 'official_exams';
    public const WORKSHOPS = 'workshops';
    public const INTERNSHIPS = 'internships';
    public const FEES_PRIVATE = 'fees_private';
    public const FEES_PUBLIC_CATALOG = 'fees_public_catalog';
    public const AUTHORIZATIONS = 'authorizations';
    public const PAYROLL_SIMPLE = 'payroll_simple';
    public const NETWORK_INDICATORS = 'network_indicators';
    public const BOARDING = 'boarding';
    public const CANTEEN_TRANSPORT = 'canteen_transport';
    public const LIBRARY = 'library';
    public const INFIRMARY = 'infirmary';

    public const ALL = [
        self::SECOND_CYCLE, self::ORIENTATION, self::OFFICIAL_EXAMS, self::WORKSHOPS, self::INTERNSHIPS, self::FEES_PRIVATE,
        self::FEES_PUBLIC_CATALOG, self::AUTHORIZATIONS, self::PAYROLL_SIMPLE, self::NETWORK_INDICATORS, self::BOARDING,
        self::CANTEEN_TRANSPORT, self::LIBRARY, self::INFIRMARY,
    ];

    /** Cycle sans lequel un module n'a pas de sens (RG-ORG-1) : il ne peut pas être activé tant que le profil n'offre pas ce cycle. */
    public const REQUIRED_CYCLE = [
        self::SECOND_CYCLE => Cycles::SECOND,
        self::ORIENTATION => Cycles::SECOND,
    ];

    /**
     * Sous-modules dont TOUTE route doit déclarer l'un de ces modules (vérifié par SecondaryConventionsTest, T814/T958). Un
     * sous-module absent de la liste appartient au noyau, ou mélange noyau et modules (Fees) : ses routes de module déclarent
     * alors leur module elles-mêmes.
     *
     * @var array<string, list<string>>
     */
    public const OWNED_BY = [
        'Workshops' => [self::WORKSHOPS, self::INTERNSHIPS],
        'OfficialExams' => [self::OFFICIAL_EXAMS],
        'Services' => [self::BOARDING, self::CANTEEN_TRANSPORT, self::LIBRARY, self::INFIRMARY],
    ];

    private function __construct()
    {
    }
}
