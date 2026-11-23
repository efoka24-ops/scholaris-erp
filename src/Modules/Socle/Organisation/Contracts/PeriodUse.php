<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Un usage d'une période par un module : libellé lisible (« évaluation(s) »), nombre de lignes et, pour les éléments datés
 * (épreuve tenue le…), la première et la dernière date AAAA-MM-JJ, qui doivent rester dans les dates de la période.
 */
final class PeriodUse
{
    public function __construct(
        public readonly string $label,
        public readonly int $count,
        public readonly ?string $firstOn = null,
        public readonly ?string $lastOn = null,
    ) {
    }

    public function describe(): string
    {
        return $this->count . ' ' . $this->label;
    }
}
