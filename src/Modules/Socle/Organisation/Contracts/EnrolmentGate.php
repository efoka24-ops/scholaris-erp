<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

use DateTimeInterface;

/**
 * Barrière d'inscription : une structure sans autorisation administrative valide à la date demandée
 * ne peut pas recevoir d'inscriptions. Consommée par le module People (inscriptions).
 */
interface EnrolmentGate
{
    public function canEnrol(string $tenantId, string $establishmentId, string $structureId, DateTimeInterface $on): bool;
}
