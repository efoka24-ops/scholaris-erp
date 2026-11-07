<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Contracts;

use Scholaris\Modules\Apps\Higher\Support\Perimeter;
use Scholaris\Modules\Apps\Higher\Support\StatisticsFilter;

/**
 * Recouvrement des frais de scolarité en agrégats SQL (tableaux de bord PIL). Montants en XAF, aucune donnée nominative.
 * Le périmètre porte sur l'école et la filière du compte de scolarité ; l'année filtre les comptes, la période les encaissements.
 */
interface CollectionStatistics
{
    /**
     * `expected` : net à payer ; `collected` : déjà encaissé ; `outstanding` : reste dû ; `overdue` : part des tranches échues et non
     * soldées à la date donnée ; `overdue_accounts` : comptes ayant au moins une tranche échue impayée.
     *
     * @return list<array{school_id: string, program_id: ?string, accounts: int, expected_xaf: int, collected_xaf: int, outstanding_xaf: int, overdue_xaf: int, overdue_accounts: int}>
     */
    public function bySchoolProgram(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter, string $today): array;

    /**
     * Encaissements confirmés par canal de paiement sur la période.
     *
     * @return list<array{channel: string, payments: int, amount_xaf: int}>
     */
    public function byChannel(string $tenantId, string $establishmentId, Perimeter $perimeter, StatisticsFilter $filter): array;
}
