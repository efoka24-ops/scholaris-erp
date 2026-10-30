<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Infrastructure;

use Scholaris\Modules\Socle\Organisation\Contracts\EstablishmentInfo;
use Scholaris\Modules\Socle\Organisation\Domain\AccentColor;

/** Traduit une ligne `establishments` en objet de contrat. */
final class EstablishmentMapper
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $row */
    public static function toInfo(array $row): EstablishmentInfo
    {
        $branding = self::decode((string) $row['branding']);
        $logo = $branding['logo'] ?? null;

        return new EstablishmentInfo(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['name'],
            (string) $row['type'],
            is_string($branding['accent'] ?? null) ? $branding['accent'] : AccentColor::DEFAULT,
            is_array($logo) && is_string($logo['path'] ?? null) ? $logo['path'] : null,
            is_string($row['establishment_subtype'] ?? null) ? $row['establishment_subtype'] : null,
        );
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
