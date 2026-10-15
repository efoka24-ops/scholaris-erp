<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Identity\Contracts;

/**
 * Source de tâches « à faire » de la personne connectée (approbations, signatures, saisies en attente).
 * Les modules concernés fournissent leur implémentation ; Identity agrège.
 */
interface TodoProvider
{
    /** @return list<array{kind: string, title: string, due_at?: string|null, link?: string|null}> */
    public function forUser(string $tenantId, string $userId): array;
}
