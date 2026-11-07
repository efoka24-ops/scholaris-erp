<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Staff\Http\Resources;

/** Sortie à liste blanche : jamais tenant_id, chemin de stockage ni colonne interne. */
abstract class Resource
{
    /** @return list<string> champs exposés, dans l'ordre */
    abstract protected function fields(): array;

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toArray(array $row): array
    {
        $out = [];
        foreach ($this->fields() as $field) {
            $out[$field] = $row[$field] ?? null;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function collection(array $rows): array
    {
        return array_map($this->toArray(...), $rows);
    }
}
