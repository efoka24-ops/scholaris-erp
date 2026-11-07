<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Contracts;

/**
 * Version observable d'un nœud territorial. Les dates sont des valeurs ISO 8601 avec fuseau ; `code` ne porte aucune
 * garantie officielle (les fixtures locales utilisent uniquement des valeurs SYN-*).
 */
final class TerritoryInfo
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $id,
        /** region, departement ou arrondissement ; cette taxonomie reste provisoire et ne vaut pas validation officielle. */
        public readonly string $type,
        public readonly string $labelFr,
        public readonly string $labelEn,
        public readonly ?string $parentId,
        public readonly string $validFrom,
        public readonly ?string $validTo,
        public readonly string $version,
        public readonly string $code,
    ) {
    }
}
