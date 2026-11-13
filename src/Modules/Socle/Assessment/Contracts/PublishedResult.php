<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Résultat publié d'un apprenant pour une période (jamais de note non publiée). Les champs après $decision sont
 * facultatifs : crédits, mention, détail par unité (moyenne, lettre, points, statut) et session (normal, resit).
 */
final class PublishedResult
{
    /** @param list<array<string, mixed>> $details */
    public function __construct(
        public readonly string $id,
        public readonly string $personId,
        public readonly string $establishmentId,
        public readonly string $periodId,
        public readonly string $periodLabel,
        public readonly ?float $average,
        public readonly ?int $rank,
        public readonly ?int $classSize,
        public readonly ?string $decision,
        public readonly ?int $creditsEarned = null,
        public readonly ?int $creditsTotal = null,
        public readonly ?string $mention = null,
        public readonly array $details = [],
        public readonly string $session = 'normal',
    ) {
    }
}
