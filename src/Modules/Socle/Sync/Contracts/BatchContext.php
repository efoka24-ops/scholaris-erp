<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Contracts;

use Scholaris\Core\Security\Principal;

/**
 * Ce que le gestionnaire sait du lot : qui l'a envoyé (personne authentifiée), pour quel client et quel établissement,
 * depuis quel appareil et sous quel identifiant de lot. Le contexte de client du noyau (TenantContext) est déjà renseigné.
 */
final class BatchContext
{
    public function __construct(
        public readonly Principal $principal,
        public readonly string $establishmentId,
        public readonly string $deviceId,
        public readonly string $batchId,
    ) {
    }

    public function tenantId(): string
    {
        return $this->principal->tenantId;
    }
}
