<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Pupils\Application;

use Scholaris\Modules\Socle\Organisation\Contracts\SettingsReader;
use Scholaris\Modules\Socle\Organisation\Contracts\TerritoryDirectory;

/**
 * Étendue de la recherche de doublons (EX-ELV-03) : les écoles du département de l'école (paramètre
 * `public_schools.duplicates.territory_level`, défaut `departement`). Null = pas de restriction (école non rattachée à un territoire).
 */
final class DuplicateScope
{
    private const LEVELS = ['region', 'departement', 'arrondissement'];

    public function __construct(
        private readonly SettingsReader $settings,
        private readonly TerritoryDirectory $territories,
        private readonly PupilClock $clock,
    ) {
    }

    /** @return list<string>|null */
    public function establishments(string $tenantId, string $establishmentId): ?array
    {
        $configured = $this->settings->value($tenantId, $establishmentId, 'public_schools.duplicates.territory_level', 'departement');
        $level = is_string($configured) && in_array($configured, self::LEVELS, true) ? $configured : 'departement';
        $at = $this->clock->now();
        $own = $this->territories->territoryOf($tenantId, $establishmentId, $at);
        if ($own === null) {
            return null;
        }
        foreach ($this->territories->lineage($tenantId, $own->id, $at) as $node) {
            if ($node->type === $level) {
                $establishments = $this->territories->establishmentsIn($tenantId, $node->id, $at);

                return $establishments === [] ? null : $establishments;
            }
        }

        return null;
    }
}
