<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Attendance\Infrastructure;

use Scholaris\Modules\Socle\Identity\Contracts\UserPersonLink;
use Scholaris\Modules\Socle\People\Contracts\BirthInfo;

/** Personne derrière un compte (contrat Identity) et majorité d'un apprenant (contrat People). */
final class PdoActorDirectory
{
    public function __construct(private readonly UserPersonLink $links, private readonly BirthInfo $births)
    {
    }

    public function personIdOf(string $tenantId, string $userId): ?string
    {
        return $this->links->personIdForUser($tenantId, $userId);
    }

    /** Sans date de naissance connue, l'apprenant est traité comme mineur (le parent garde l'accès). */
    public function isAdult(string $tenantId, string $personId, string $today): bool
    {
        return $this->births->isAdult($tenantId, $personId, $today) === true;
    }
}
