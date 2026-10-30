<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Contracts;

/** Registre des documents de l'IPES émis pour une personne (portails étudiant et garant). */
interface IssuedDocuments
{
    /** @return list<array<string, mixed>> documents de la personne (code, numéro, statut, langue, date), du plus récent au plus ancien */
    public function forPerson(string $tenantId, string $personId): array;
}
