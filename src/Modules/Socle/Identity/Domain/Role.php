<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/** Rôle standard (table `roles`) : niveau client ou établissement, sensibilité (2FA obligatoire). */
final class Role
{
    public const SUPER_ADMIN = 'super_admin';
    public const GROUP_DIRECTION = 'direction_groupe';
    /** Rôle global de la plateforme : rattaché au client technique `platform`, jamais attribuable par une route ni par un administrateur de client. */
    public const PLATFORM_ADMIN = 'platform_admin';
    public const PLATFORM_TENANT = 'platform';

    public function __construct(
        public readonly string $code,
        /** « tenant » : rôle de niveau client ; « establishment » : rôle d'établissement */
        public readonly string $level,
        public readonly bool $sensitive,
        public readonly string $labelFr,
        public readonly string $labelEn,
        /** PP2 : rôle de supervision à agrégats seuls, jamais porteur d'une permission nominative `*.named.*` */
        public readonly bool $aggregateOnly = false,
    ) {
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? $this->labelEn : $this->labelFr;
    }

    public function isTenantLevel(): bool
    {
        return $this->level === 'tenant';
    }
}
