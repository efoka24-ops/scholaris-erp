<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Domain;

/** Attente avant une nouvelle tentative d'envoi : exponentielle (base, 2 x base, 4 x base...) plafonnée. */
final class RetryBackoff
{
    public function __construct(
        private readonly int $baseSeconds = 60,
        private readonly int $capSeconds = 3600,
    ) {
    }

    /** @param int $attempts nombre de tentatives déjà faites (au moins 1) */
    public function delaySeconds(int $attempts): int
    {
        $exponent = max(0, min($attempts, 30) - 1);

        return min($this->capSeconds, $this->baseSeconds * (2 ** $exponent));
    }
}
