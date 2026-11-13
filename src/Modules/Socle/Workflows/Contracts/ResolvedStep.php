<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/** Étape telle qu'elle s'applique : acteur, activation, confirmation récente, et d'où vient chaque valeur. */
final class ResolvedStep
{
    public function __construct(
        public readonly string $key,
        public readonly bool $enabled,
        public readonly Actor $actor,
        public readonly bool $recentConfirmation,
        /** default, platform, client ou establishment : niveau du dernier réglage appliqué à cette étape */
        public readonly string $source,
        /** platform ou client si ce niveau a imposé l'étape (plancher), sinon null */
        public readonly ?string $enforcedBy,
    ) {
    }

    public function permission(): string
    {
        return $this->actor->permission;
    }

    public function role(): ?string
    {
        return $this->actor->role;
    }
}
