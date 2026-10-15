<?php

declare(strict_types=1);

namespace Tests\Support\People;

use DateTimeInterface;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;

/** Doublure de la barrière d'inscription : seules les structures ouvertes sont acceptées. */
final class OpenStructures implements EnrolmentGate
{
    /** @var list<string> */
    public array $open = ['s1'];

    public function canEnrol(string $tenantId, string $establishmentId, string $structureId, DateTimeInterface $on): bool
    {
        return in_array($structureId, $this->open, true);
    }
}
