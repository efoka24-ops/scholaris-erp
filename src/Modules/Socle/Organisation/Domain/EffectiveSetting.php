<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/**
 * Valeur effective d'un paramètre : la valeur du niveau le plus spécifique qui la définit (`source`, et l'unité pour la portée
 * `org_unit`), avec les valeurs masquées des niveaux plus généraux (`overridden`) pour que la surcharge reste visible.
 */
final class EffectiveSetting
{
    /** @param list<SettingEntry> $overridden niveaux masqués, du plus proche au plus général */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly SettingScope $source,
        public readonly array $overridden,
        public readonly ?string $sourceOrgUnitId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'key' => $this->key,
            'value' => $this->value,
            'source' => $this->source->value,
        ];
        if ($this->sourceOrgUnitId !== null) {
            $out['source_org_unit_id'] = $this->sourceOrgUnitId;
        }
        $out['overridden'] = array_map(static fn (SettingEntry $entry): array => $entry->toArray(), $this->overridden);

        return $out;
    }
}
