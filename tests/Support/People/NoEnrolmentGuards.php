<?php

declare(strict_types=1);

namespace Tests\Support\People;

use DateTimeInterface;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;

/**
 * Aucun garde-fou d'inscription : les tests People travaillent sur des structures inventées, que le garde réel d'une
 * application chargée par le Kernel (Higher) refuserait. Les tests des points d'extension fournissent leurs propres gardes.
 */
final class NoEnrolmentGuards implements EnrolmentGuards
{
    public function refusal(
        string $tenantId,
        string $establishmentId,
        string $structureId,
        ?string $academicYearId,
        ?string $personId,
        DateTimeInterface $on,
        ?string $enrollmentId = null,
    ): ?string {
        return null;
    }
}
