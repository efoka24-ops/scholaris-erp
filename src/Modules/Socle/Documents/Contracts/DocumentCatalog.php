<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Contracts;

/** Documents émis pour une personne (portails). À fournir par le module Documents. */
interface DocumentCatalog
{
    /**
     * @return list<IssuedDocument> documents valides de la personne, du plus récent au plus ancien (les révoqués sont exclus)
     */
    public function validFor(string $tenantId, string $personId, int $limit = 50): array;
}
