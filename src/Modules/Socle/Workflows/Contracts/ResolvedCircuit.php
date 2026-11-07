<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Workflows\Contracts;

/** Circuit résolu pour un établissement : défauts de l'application, puis plateforme, client et établissement. */
final class ResolvedCircuit
{
    /** @param array<string, ResolvedStep> $steps clé d'étape => étape résolue, dans l'ordre du circuit */
    public function __construct(
        public readonly Circuit $circuit,
        public readonly array $steps,
        public readonly bool $customised,
    ) {
    }

    public function step(string $key): ResolvedStep
    {
        return $this->steps[$key] ?? throw new \LogicException('Étape inconnue du circuit ' . $this->circuit->key . ' : ' . $key);
    }
}
