<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicNursery\Support;

use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Security\Principal;
use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentDirectory;

/** Garde locale : un établissement doit être de type nursery et une section doit correspondre à sa portée. */
final class EstablishmentGuard
{
    public function __construct(
        private readonly NurseryWriteGate $gate,
        private readonly EstablishmentDirectory $establishments,
    ) {
    }

    public function assertNursery(Principal|string $who, string $tenantId, string $establishmentId): void
    {
        $this->gate->assertReadable($who);
        $establishment = $this->establishments->find($tenantId, $establishmentId)
            ?? throw HttpException::notFound('Établissement introuvable.');
        if ($establishment->type !== 'nursery') {
            throw HttpException::notFound('Cette route ne concerne que les établissements de type maternelle publique.');
        }
    }

    public function assertSection(Principal|string $who, SectionScope $scope, string $sectionId): void
    {
        $this->gate->assertReadable($who);
        if (!$scope->contains($sectionId)) {
            throw HttpException::notFound('La section demandée ne relève pas de votre périmètre.');
        }
    }
}
