<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Contracts;

/** Critère d'évaluation d'une discipline : minimal (compte pour la mention) ou de perfectionnement (signalé si la mention est A). */
final class CriterionInfo
{
    public const MINIMAL = 'minimal';
    public const PERFECTION = 'perfection';

    public function __construct(
        public readonly string $id,
        public readonly string $frameId,
        public readonly string $code,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly string $kind,
        public readonly int $sortOrder,
    ) {
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? $this->labelEn : $this->labelFr;
    }
}
