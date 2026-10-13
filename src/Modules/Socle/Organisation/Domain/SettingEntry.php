<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/** Valeur d'un paramètre à un niveau précis (pour la portée `org_unit`, l'unité qui la définit). */
final class SettingEntry
{
    public function __construct(
        public readonly SettingScope $scope,
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $updatedBy,
        public readonly string $updatedAt,
        public readonly ?string $orgUnitId = null,
    ) {
    }

    /** @return array<string, mixed> scope, key, value, updated_by, updated_at (et org_unit_id pour la portée `org_unit`) */
    public function toArray(): array
    {
        $out = [
            'scope' => $this->scope->value,
            'key' => $this->key,
            'value' => $this->value,
            'updated_by' => $this->updatedBy,
            'updated_at' => $this->updatedAt,
        ];
        if ($this->orgUnitId !== null) {
            $out['org_unit_id'] = $this->orgUnitId;
        }

        return $out;
    }
}
