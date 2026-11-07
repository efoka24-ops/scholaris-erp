<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Ce qu'une garde de délibération sait de l'opération demandée (S8). À l'ouverture, la délibération n'existe pas encore
 * (deliberationId null). L'empreinte stockée est celle calculée à la clôture ; l'empreinte courante est recalculée sur
 * les décisions en base au moment de l'opération.
 */
final class DeliberationContext
{
    /**
     * @param list<string> $membersPresent personnes présentes
     * @param list<array{person_id: string, user_id: string, signed_at: string}> $signatures
     */
    public function __construct(
        public readonly string $stage,
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $userId,
        public readonly ?string $deliberationId,
        public readonly string $structureId,
        public readonly string $periodId,
        public readonly string $session,
        public readonly ?string $status = null,
        public readonly ?string $presidentId = null,
        public readonly array $membersPresent = [],
        public readonly array $signatures = [],
        public readonly ?string $storedFingerprint = null,
        public readonly ?string $currentFingerprint = null,
        public readonly int $minutesVersion = 0,
        public readonly ?string $reason = null,
    ) {
    }

    public function hasSigned(string $personId): bool
    {
        foreach ($this->signatures as $signature) {
            if ($signature['person_id'] === $personId) {
                return true;
            }
        }

        return false;
    }
}
