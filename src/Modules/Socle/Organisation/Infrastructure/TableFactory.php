<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Core\Database\ConnectionFactory;
use Scholaris\Core\Tenancy\TenantContext;
use Scholaris\Core\Tenancy\TenantTable;

/**
 * Fabrique de TenantTable. `request()` suit le contexte de la requête HTTP ; `explicit()` sert aux services
 * appelés avec un client et un établissement précis (contrats consommés par d'autres modules) : un contexte
 * local est créé, le filtre tenant reste donc obligatoire dans tous les cas.
 */
final class TableFactory
{
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly TenantContext $context,
    ) {
    }

    public function request(string $table, bool $byEstablishment): TenantTable
    {
        $tenantTable = new TenantTable($this->connections, $this->context, $table);

        return $byEstablishment ? $tenantTable->forEstablishment() : $tenantTable;
    }

    public function explicit(string $tenantId, ?string $establishmentId, string $table, bool $byEstablishment): TenantTable
    {
        $context = new TenantContext();
        $context->set($tenantId, $establishmentId);
        $tenantTable = new TenantTable($this->connections, $context, $table);

        return $byEstablishment ? $tenantTable->forEstablishment() : $tenantTable;
    }
}
