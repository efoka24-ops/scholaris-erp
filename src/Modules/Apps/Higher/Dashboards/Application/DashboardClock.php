<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Dashboards\Application;

/** Horloge des tableaux de bord (date du jour AAAA-MM-JJ, fuseau de l'application) ; une date fixe peut être imposée pour les tests. */
final class DashboardClock
{
    public function __construct(private readonly ?string $fixedToday = null)
    {
    }

    public function today(): string
    {
        return $this->fixedToday ?? date('Y-m-d');
    }

    public function now(): string
    {
        return $this->fixedToday !== null ? $this->fixedToday . 'T00:00:00' : date('c');
    }
}
