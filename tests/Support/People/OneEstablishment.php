<?php

declare(strict_types=1);

namespace Tests\Support\People;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;

final class OneEstablishment implements EstablishmentDirectory
{
    public function __construct(private readonly string $type = 'secondary')
    {
    }

    public function forTenant(string $tenantId): array
    {
        return [new EstablishmentInfo('e1', $tenantId, 'Collège Les Palmiers', $this->type, '#F26B1D')];
    }

    public function findMany(string $tenantId, array $ids): array
    {
        return $this->forTenant($tenantId);
    }

    public function find(string $tenantId, string $establishmentId): ?EstablishmentInfo
    {
        return $this->forTenant($tenantId)[0];
    }
}
