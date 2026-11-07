<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/**
 * Verrou de sécurité expliqué à l'administrateur. `aspect` : actor, enabled, confirmation (sur une étape) ou circuit (règle
 * qui vaut pour tout le circuit : séparation des tâches, procès-verbal signé, immuabilité, audit...).
 */
final class Lock
{
    public const ACTOR = 'actor';
    public const ENABLED = 'enabled';
    public const CONFIRMATION = 'confirmation';
    public const CIRCUIT = 'circuit';

    public function __construct(
        public readonly string $aspect,
        public readonly string $code,
        public readonly string $reasonFr,
        public readonly string $reasonEn,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['aspect' => $this->aspect, 'code' => $this->code, 'reason_fr' => $this->reasonFr, 'reason_en' => $this->reasonEn];
    }
}
