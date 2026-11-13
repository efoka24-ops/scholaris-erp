<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Domain;

/** Périmètre d'une affectation de rôle. */
enum ScopeType: string
{
    case Tenant = 'tenant';
    case Establishment = 'establishment';
    case Territory = 'territory';
    case OrgUnit = 'org_unit';
    case ClassGroup = 'class';
    /** structure pédagogique, ex. une filière du supérieur (S15) */
    case Structure = 'structure';
}
