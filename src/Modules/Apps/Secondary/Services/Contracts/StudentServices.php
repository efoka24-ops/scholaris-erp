<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Services\Contracts;

/**
 * Abonnements en cours d'un élève (internat, cantine, transport), en LECTURE pour les portails élève et parent : nature, offre, arrêt,
 * dates. Jamais de montant (le solde est celui du socle Billing) ni de donnée de santé.
 */
interface StudentServices
{
    /** @return list<array<string, mixed>> id, service_kind, offer_label, stop_label, starts_on, ends_on */
    public function currentFor(string $tenantId, string $establishmentId, string $personId): array;
}
