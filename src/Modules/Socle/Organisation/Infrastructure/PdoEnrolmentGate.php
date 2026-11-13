<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use DateTimeImmutable;
use DateTimeInterface;
use Scholaris\Modules\Socle\Organisation\Application\EnrolmentGuardProviders;
use Scholaris\Modules\Socle\Organisation\Contracts\AuthorizationRegistry;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGate;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentGuards;
use Scholaris\Modules\Socle\Organisation\Contracts\EnrolmentRequest;

/**
 * Inscription possible seulement si la structure existe dans l'établissement du client et qu'une autorisation valide ce jour-là la
 * couvre (sur elle-même ou une structure ascendante ; niveaux couverts s'ils sont restreints). Sans hiérarchie ni restriction de
 * niveaux, c'est le contrôle historique : une autorisation valide sur la structure elle-même.
 * Les gardes-fous des applications (D3) sont exécutés par refusal(), appelé par People à la validation d'une inscription.
 */
final class PdoEnrolmentGate implements EnrolmentGate, EnrolmentGuards
{
    public function __construct(
        private readonly Repositories $repositories,
        private readonly AuthorizationRegistry $authorizations,
        private readonly PdoOrgUnitTree $tree,
        private readonly EnrolmentGuardProviders $guards,
    ) {
    }

    public function canEnrol(string $tenantId, string $establishmentId, string $structureId, DateTimeInterface $on): bool
    {
        if ($this->repositories->explicit('establishments', $tenantId)->find($establishmentId) === null) {
            return false;
        }
        if ($this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->find($structureId) === null) {
            return false;
        }

        return $this->authorizations->covers($tenantId, $establishmentId, $structureId, null, $on);
    }

    public function refusal(
        string $tenantId,
        string $establishmentId,
        string $structureId,
        ?string $academicYearId,
        ?string $personId,
        DateTimeInterface $on,
        ?string $enrollmentId = null,
    ): ?string {
        $guards = $this->guards->guards();
        if ($guards === []) {
            return null;
        }
        $establishment = $this->repositories->explicit('establishments', $tenantId)->find($establishmentId);
        if ($establishment === null || $this->repositories->explicit('pedagogical_structures', $tenantId, $establishmentId)->find($structureId) === null) {
            return 'Structure introuvable dans cet établissement.';
        }
        $request = new EnrolmentRequest(
            $tenantId,
            $establishmentId,
            (string) $establishment['type'],
            $structureId,
            $academicYearId,
            $personId,
            DateTimeImmutable::createFromInterface($on),
            $this->tree->levelOf($tenantId, $establishmentId, $structureId),
            $this->tree->trackOf($tenantId, $establishmentId, $structureId),
            $enrollmentId,
        );
        foreach ($guards as $guard) {
            $reason = $guard->refusal($request);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
