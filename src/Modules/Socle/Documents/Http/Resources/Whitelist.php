<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Http\Resources;

/** Sortie à liste blanche : seuls les champs déclarés sont exposés (jamais tenant_id, contenu rendu ni colonne interne). */
final class Whitelist
{
    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @param list<string> $json champs stockés en JSON, restitués décodés
     * @return array<string, mixed>
     */
    public static function pick(array $row, array $fields, array $json = []): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, $json, true) && is_string($value)) {
                $value = json_decode($value, true);
            }
            $out[$field] = $value;
        }

        return $out;
    }
}
