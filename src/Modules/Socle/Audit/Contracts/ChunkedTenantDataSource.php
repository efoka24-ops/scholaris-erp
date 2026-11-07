<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Audit\Contracts;

/**
 * Source d'export lisible par lots : l'exportateur écrit chaque lot sur disque avant de lire le suivant, de sorte que la
 * mémoire reste bornée quel que soit le volume (tenant:export sur un client de plusieurs centaines de milliers de lignes).
 */
interface ChunkedTenantDataSource extends TenantDataSource
{
    /**
     * Les lots d'une même table sont consécutifs. Une table sans ligne produit un seul lot vide.
     *
     * @return iterable<array{0: string, 1: list<array<string, mixed>>}> couples [table, lot de lignes]
     */
    public function exportChunks(string $tenantId, int $batchSize = 500): iterable;
}
