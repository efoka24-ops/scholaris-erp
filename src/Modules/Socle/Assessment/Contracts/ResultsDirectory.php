<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/** Résultats publiés pour les portails et documents. Fourni par le module Assessment (S09). */
interface ResultsDirectory
{
    /** @return list<PublishedResult> résultats publiés, du plus récent au plus ancien */
    public function publishedFor(string $tenantId, string $personId): array;
}
