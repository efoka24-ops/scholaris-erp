<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Source de tâches « à traiter » (signature de PV, saisie de notes à faire…). Enregistrement par une définition de
 * conteneur `reporting.todo.<nom>` dont la valeur est la liste des classes de sources.
 */
interface TodoProvider
{
    /** @return list<array{kind: string, title: string, due_at?: string|null, link?: string|null}> */
    public function forUser(string $tenantId, string $userId): array;
}
