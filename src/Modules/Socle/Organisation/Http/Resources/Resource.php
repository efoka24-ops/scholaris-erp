<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Http\Resources;

/**
 * Sortie d'une ressource : seuls les champs de la liste blanche sont exposés (jamais tenant_id ni colonne interne).
 */
abstract class Resource
{
    /** @return list<string> champs exposés, dans l'ordre */
    abstract protected function fields(): array;

    /** @return list<string> champs stockés en JSON, restitués décodés */
    protected function jsonFields(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toArray(array $row): array
    {
        $out = [];
        foreach ($this->fields() as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, $this->jsonFields(), true) && is_string($value)) {
                $value = json_decode($value, true);
            }
            $out[$field] = $value;
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
