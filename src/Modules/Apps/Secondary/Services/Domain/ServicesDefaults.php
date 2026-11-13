<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Domain;

use Scholaris\Modules\Apps\Secondary\Setup\Contracts\Modules;

/**
 * Valeurs de départ des services annexes (« à confirmer » au pilote) : natures de service par module, motifs de passage à
 * l'infirmerie, suites données, bornes de saisie. Aucun tarif : le montant d'une offre est saisi par l'établissement et le frais du
 * catalogue Billing est désigné par son code (natures `canteen`, `transport`, `boarding` déclarées par Fees).
 */
final class ServicesDefaults
{
    public const CANTEEN = 'canteen';
    public const TRANSPORT = 'transport';
    public const BOARDING = 'boarding';

    /** @var array<string, list<string>> module activable => natures de service qu'il porte */
    public const KINDS_BY_MODULE = [
        Modules::CANTEEN_TRANSPORT => [self::CANTEEN, self::TRANSPORT],
        Modules::BOARDING => [self::BOARDING],
    ];

    public const REASONS = ['illness', 'injury', 'checkup', 'medication', 'other'];
    public const OUTCOMES = ['back_to_class', 'rest', 'sent_home', 'referred'];

    public const MAX_NOTES = 2000;
    public const MAX_RECORD_FIELD = 2000;
    public const MAX_STOPS = 40;
    public const MAX_AMOUNT_XAF = 10000000;
    public const MAX_CAPACITY = 5000;
    /** Durée maximale d'une dispense d'EPS saisie au passage (jours) : au-delà, certificat médical et décision de l'établissement. */
    public const MAX_EXEMPTION_DAYS = 366;

    private function __construct()
    {
    }
}
