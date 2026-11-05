<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Contracts;

/** Relevé semestriel d'un étudiant, tiré des seuls résultats publiés après PV signé (RG-DOC-3). Fourni par TranscriptService. */
interface SemesterTranscripts
{
    /**
     * @return array<string, mixed> identité, en-tête, UE et EC, synthèse (voir TranscriptService::build)
     * @throws \Scholaris\Core\Http\HttpException 404 s'il n'y a pas de résultat publié après signature du PV
     */
    public function build(string $tenantId, string $establishmentId, string $personId, string $periodId, string $locale = 'fr'): array;
}
