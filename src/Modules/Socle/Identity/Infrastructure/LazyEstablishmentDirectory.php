<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Infrastructure;

use Psr\Container\ContainerInterface;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;

/**
 * Résout l'annuaire des établissements au premier usage : Identity peut démarrer (connexion, activation)
 * même si le module Organisation n'a pas encore déclaré sa liaison dans le conteneur.
 */
final class LazyEstablishmentDirectory implements EstablishmentDirectory
{
    private ?EstablishmentDirectory $inner = null;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function forTenant(string $tenantId): array
    {
        return $this->directory()->forTenant($tenantId);
    }

    public function findMany(string $tenantId, array $ids): array
    {
        return $this->directory()->findMany($tenantId, $ids);
    }

    public function find(string $tenantId, string $establishmentId): ?EstablishmentInfo
    {
        return $this->directory()->find($tenantId, $establishmentId);
    }

    private function directory(): EstablishmentDirectory
    {
        return $this->inner ??= $this->container->get(EstablishmentDirectory::class);
    }
}
