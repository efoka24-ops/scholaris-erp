<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/**
 * Établissements où l'application secondaire est ouverte (profil copié), tous clients confondus, en LECTURE : tâches planifiées du
 * secondaire (relances NOT-07, NOT-08, NOT-10). Le profil de chaque établissement se lit ensuite par EstablishmentProfiles.
 */
interface OpenedEstablishments
{
    /** @return list<array{tenant_id: string, establishment_id: string}> */
    public function all(): array;
}
