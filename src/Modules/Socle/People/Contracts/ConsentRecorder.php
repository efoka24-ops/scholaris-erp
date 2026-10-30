<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Consentements horodatés, révocables et traçables par preuve écrite.
 * `evidence_ref` reste optionnelle pour les cas où la preuve est matérialisée ailleurs.
 */
interface ConsentRecorder
{
    /**
     * Enregistre un consentement, éventuellement avec une référence de preuve.
     *
     * @return array{id: string, person_id: string, purpose: string, granted_at: string, granted_by: string|null, revoked_at: string|null, revoked_by: string|null, evidence_ref: string|null}
     */
    public function grant(string $tenantId, string $personId, string $purpose, string $actorId, ?string $evidenceRef = null): array;

    /**
     * Révoque le consentement identifié, sans supprimer l'historique.
     *
     * @return array{id: string, person_id: string, purpose: string, granted_at: string, granted_by: string|null, revoked_at: string|null, revoked_by: string|null, evidence_ref: string|null}
     */
    public function revoke(string $tenantId, string $consentId, string $actorId): array;

    /** Vrai si le consentement est actif et appuyé par une preuve écrite. */
    public function hasWrittenConsent(string $tenantId, string $personId, string $purpose): bool;
}