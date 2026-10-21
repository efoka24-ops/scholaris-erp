<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSchools\Pupils;

use DateTimeInterface;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;

/** Barrière d'inscription de test : toute structure est ouverte. */
final class OpenEnrolments implements EnrolmentGate
{
    public function canEnrol(string $tenantId, string $establishmentId, string $structureId, DateTimeInterface $on): bool
    {
        return true;
    }
}
