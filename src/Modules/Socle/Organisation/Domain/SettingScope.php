<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

/**
 * Niveaux de paramétrage (RG-ORG-4, RG-ORG-5), du plus général au plus spécifique : plateforme, client, établissement, unité interne
 * (école, département : portée `org_unit`, D6).
 */
enum SettingScope: string
{
    case Platform = 'platform';
    case Tenant = 'tenant';
    case Establishment = 'establishment';
    case OrgUnit = 'org_unit';

    /** Plus le rang est élevé, plus le niveau est spécifique (et prioritaire). */
    public function rank(): int
    {
        return match ($this) {
            self::Platform => 0,
            self::Tenant => 1,
            self::Establishment => 2,
            self::OrgUnit => 3,
        };
    }
}
