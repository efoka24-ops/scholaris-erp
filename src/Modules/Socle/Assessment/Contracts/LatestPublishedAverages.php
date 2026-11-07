<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Moyenne du résultat PUBLIÉ le plus récent de chaque apprenant d'un établissement, en une seule lecture (tableaux de bord).
 * Contrat distinct de ResultsDirectory pour ne pas imposer de nouvelle méthode à ses implémentations existantes.
 */
interface LatestPublishedAverages
{
    /** @return array<string, float> moyenne par identifiant de personne (résultats publiés à moyenne connue seulement) */
    public function latestAveragesIn(string $tenantId, string $establishmentId): array;
}
