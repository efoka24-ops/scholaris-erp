<?php

declare(strict_types=1);

namespace Scholaris\Core\Tenancy;

/**
 * Contexte de la requête : client (tenant) et établissement actif. Renseigné par le middleware
 * ResolveEstablishment après contrôle des droits. Sans contexte, les dépôts refusent toute requête.
 */
final class TenantContext
{
    private ?string $tenantId = null;
    private ?string $establishmentId = null;

    public function set(string $tenantId, ?string $establishmentId = null): void
    {
        $this->tenantId = $tenantId;
        $this->establishmentId = $establishmentId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->establishmentId = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenantId(): string
    {
        if ($this->tenantId === null) {
            throw new MissingTenantContext('Aucun client (tenant) dans le contexte : requête refusée.');
        }

        return $this->tenantId;
    }

    public function establishmentId(): string
    {
        if ($this->establishmentId === null) {
            throw new MissingTenantContext('Aucun établissement dans le contexte : requête refusée.');
        }

        return $this->establishmentId;
    }
}
