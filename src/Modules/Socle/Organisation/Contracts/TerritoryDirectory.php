<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Lecture de la hiérarchie territoriale versionnée, filtrée par tenant et à une date explicite.
 * Les implémentations sont propriétaires d'Organisation ; les consommateurs n'accèdent pas à ses tables.
 */
interface TerritoryDirectory
{
    /** Version du territoire valide à l'instant ISO 8601 indiqué, ou null si absent/inactif/hors tenant. */
    public function find(string $tenantId, string $territoryId, string $at): ?TerritoryInfo;

    /**
     * Lignée historique, territoire lui-même puis parents jusqu'à la racine, tous valides à `at` ; [] en cas d'identifiant
     * inconnu, de parent hors tenant ou de chaîne incohérente.
     *
     * @return list<TerritoryInfo>
     */
    public function lineage(string $tenantId, string $territoryId, string $at): array;

    /**
     * Territoire lui-même puis descendants, évalués à `at` et bornés au tenant ; [] si la racine demandée est inconnue.
     *
     * @return list<TerritoryInfo>
     */
    public function descendantsOf(string $tenantId, string $territoryId, string $at): array;

    /** Territoire auquel l'établissement est rattaché à l'instant `at` (RG-TER-4), ou null si aucun rattachement valide. */
    public function territoryOf(string $tenantId, string $establishmentId, string $at): ?TerritoryInfo;

    /**
     * Identifiants des établissements rattachés à `territoryId` ou à l'un de ses descendants à l'instant `at`, triés ;
     * [] si le territoire est inconnu ou hors tenant.
     *
     * @return list<string>
     */
    public function establishmentsIn(string $tenantId, string $territoryId, string $at): array;
}
