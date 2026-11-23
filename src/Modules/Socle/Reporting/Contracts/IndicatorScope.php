<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Périmètre d'un calcul : un client, un établissement, une date du jour et le lundi de la semaine en cours ; facultativement
 * une école interne (`org_unit_id`, tableaux de bord d'application, S21) que les fournisseurs qui la gèrent appliquent.
 */
final class IndicatorScope
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $establishmentId,
        public readonly string $today,
        public readonly string $weekStart,
        public readonly ?string $orgUnitId = null,
    ) {
    }
}
