<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Annuaire des établissements d'un client. Fourni par le module Organisation (S02),
 * consommé par Identity (contextes « mes établissements »), Reporting et les applications.
 */
interface EstablishmentDirectory
{
    /** @return list<EstablishmentInfo> établissements du client, triés par nom */
    public function forTenant(string $tenantId): array;

    /**
     * @param list<string> $ids
     * @return list<EstablishmentInfo> uniquement ceux qui appartiennent au client
     */
    public function findMany(string $tenantId, array $ids): array;

    public function find(string $tenantId, string $establishmentId): ?EstablishmentInfo;
}
