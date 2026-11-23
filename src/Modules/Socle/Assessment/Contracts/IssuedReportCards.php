<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Bulletins ÉMIS d'un conseil de classe (file `report_card_requests` à l'état « issued »), pour les applications qui annoncent la
 * publication aux familles (S-NOT05, SMS « bulletin disponible » du secondaire). Lecture seule, bornée au client et à
 * l'établissement : un conseil d'un autre client ou d'un autre établissement ne rend rien. Fourni par le module Assessment.
 */
interface IssuedReportCards
{
    /** @return list<array{person_id: string, document_id: string}> un bulletin émis par élève, dans l'ordre de la file */
    public function issuedFor(string $tenantId, string $establishmentId, string $councilId): array;
}
