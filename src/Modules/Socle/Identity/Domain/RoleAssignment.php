<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/**
 * Affectation d'un rôle à une personne dans un périmètre, avec dates de validité.
 * Les dates sont des chaînes ISO 8601 au fuseau Africa/Douala (comparables telles quelles).
 */
final class RoleAssignment
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $personId,
        public readonly string $role,
        public readonly ScopeType $scopeType,
        public readonly ?string $scopeId,
        /** établissement auquel se rattache le périmètre (null pour un rôle de niveau client) */
        public readonly ?string $establishmentId,
        public readonly string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $delegatedBy,
        public readonly ?string $createdBy,
        public readonly string $createdAt,
    ) {
    }

    public function isActiveAt(string $now): bool
    {
        return $this->startsAt <= $now && ($this->endsAt === null || $this->endsAt > $now);
    }

    public function isTenantWide(): bool
    {
        return $this->scopeType === ScopeType::Tenant;
    }

    /** Vrai si l'affectation s'exerce dans l'établissement donné (null = niveau client seulement). */
    public function appliesTo(?string $establishmentId): bool
    {
        if ($this->isTenantWide()) {
            return true;
        }

        return $establishmentId !== null && $this->establishmentId === $establishmentId;
    }
}
