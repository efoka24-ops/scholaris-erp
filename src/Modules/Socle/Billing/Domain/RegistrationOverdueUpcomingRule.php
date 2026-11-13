<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Domain;

/** Règle par défaut : frais d'inscription, puis tranches en retard (de la plus ancienne à la plus récente), puis tranches à venir. */
final class RegistrationOverdueUpcomingRule implements AllocationRule
{
    public const NAME = 'registration_overdue_upcoming';

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
        $bucket = static fn (Due $d): int => $d->kind === 'registration' ? 0 : ($d->isOverdue($today) ? 1 : 2);
        usort($dues, static fn (Due $a, Due $b): int => [$bucket($a), $a->dueOn, $a->sequence, $a->learnerId, $a->installmentId]
            <=> [$bucket($b), $b->dueOn, $b->sequence, $b->learnerId, $b->installmentId]);

        return $dues;
    }
}
