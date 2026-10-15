<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Variante paramétrable : strictement les échéances les plus anciennes d'abord, sans priorité aux frais d'inscription. */
final class OldestFirstRule implements AllocationRule
{
    public const NAME = 'oldest_first';

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::NAME . '@1';
    }

    public function order(array $dues, string $today): array
    {
        usort($dues, static fn (Due $a, Due $b): int => [$a->dueOn, $a->sequence, $a->learnerId, $a->installmentId]
            <=> [$b->dueOn, $b->sequence, $b->learnerId, $b->installmentId]);

        return $dues;
    }
}
