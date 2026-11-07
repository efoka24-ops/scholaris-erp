<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\Documents\Contracts\DocumentIssuer;

/**
 * Demande d'émission d'un document (procès-verbal, bulletin) au module Documents. Un modèle absent ou une variable non
 * déclarée par l'application ne fait jamais échouer le conseil ou la délibération : le statut le dit, on réessaie après.
 */
final class MinutesIssuer
{
    public function __construct(private readonly DocumentIssuer $issuer)
    {
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array{status: string, document_id: string|null, message: string|null}
     */
    public function issue(string $tenantId, string $establishmentId, string $type, ?string $subjectPersonId, array $data, string $issuedBy, string $locale = 'fr'): array
    {
        if ($subjectPersonId === null) {
            return ['status' => 'pending', 'document_id' => null, 'message' => 'Aucune personne de référence pour le document.'];
        }

        try {
            $document = $this->issuer->issue($tenantId, $establishmentId, $type, $subjectPersonId, $data, $issuedBy, $locale);
        } catch (HttpException $e) {
            return ['status' => in_array($e->status, [404, 422], true) ? 'template_missing' : 'failed', 'document_id' => null, 'message' => mb_substr(trim($e->getMessage() . ' ' . implode(' ', array_merge([], ...array_values($e->errors)))), 0, 255)];
        }

        return ['status' => 'issued', 'document_id' => $document->id, 'message' => null];
    }
}
