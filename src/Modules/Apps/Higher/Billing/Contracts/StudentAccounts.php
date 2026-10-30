<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Contracts;

/** Comptes de scolarité de l'IPES (un par étudiant, année et filière) : lecture pour les portails et les relances. */
interface StudentAccounts
{
    /** @return list<StudentAccountView> comptes de la personne dans le client, du plus récent au plus ancien */
    public function forPerson(string $tenantId, string $personId): array;

    /** @return list<StudentAccountView> comptes de l'établissement (relances planifiées) */
    public function forEstablishment(string $tenantId, string $establishmentId): array;

    /** @return list<array{tenant_id: string, establishment_id: string}> établissements qui ont au moins un compte (tâches planifiées) */
    public function activeScopes(): array;
}
