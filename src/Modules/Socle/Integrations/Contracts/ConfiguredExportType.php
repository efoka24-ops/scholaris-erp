<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Type d'export par valeurs, à enregistrer sous `integrations.export_types.<application>` (S14). */
final class ConfiguredExportType implements ExportType
{
    /**
     * @param list<string> $fields
     * @param array<string, string> $defaultColumns
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly array $fields,
        private readonly array $defaultColumns,
        private readonly ?string $columnsSetting = null,
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

    public function fields(): array
    {
        return $this->fields;
    }

    public function defaultColumns(): array
    {
        return $this->defaultColumns;
    }

    public function columnsSetting(): ?string
    {
        return $this->columnsSetting;
    }
}
