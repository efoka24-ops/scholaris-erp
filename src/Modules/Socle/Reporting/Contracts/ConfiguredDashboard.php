<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/** Définition de tableau de bord par valeurs, à enregistrer telle quelle sous `reporting.dashboards.<application>` (S21). */
final class ConfiguredDashboard implements DashboardDefinition
{
    /** @param array<string, string> $expected */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $expected,
        private readonly string $permission = 'reporting.read',
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function permission(): string
    {
        return $this->permission;
    }

    public function expected(): array
    {
        return $this->expected;
    }
}
