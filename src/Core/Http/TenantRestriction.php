<?php

declare(strict_types=1);

namespace Scholaris\Core\Http;

/**
 * Restriction d'accès d'un client (tenant), consultée par le middleware EnforceTenantRestriction avant tout contrôleur.
 * Le noyau ne connaît aucun module : le module qui gère les abonnements fournit l'implémentation (liaison du conteneur).
 * Sans liaison, aucun client n'est restreint.
 */
interface TenantRestriction
{
    public const READ_ONLY = 'read_only';
    public const LOCKED = 'locked';

    /** `locked` : tout est refusé sauf la liste blanche ; `read_only` : écritures refusées ; null : aucune restriction. */
    public function levelFor(string $tenantId): ?string;
}
