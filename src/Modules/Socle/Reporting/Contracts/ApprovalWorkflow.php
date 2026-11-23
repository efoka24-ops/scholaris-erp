<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Reporting\Contracts;

/**
 * Contrat générique de validation séquentielle et de snapshot gelé.
 *
 * Les étapes sont fournies par le workflow appelant ; ce contrat ne définit aucun rôle, ordre réglementaire ou délai officiel.
 * L'autorisation des personnes qui soumettent et valident reste à la charge de l'appelant.
 */
interface ApprovalWorkflow
{
    /** @param list<string> $steps identifiants ordonnés définis par le workflow appelant */
    public function submit(string $tenantId, string $workflowId, string $submittedBy, array $steps): void;

    public function activeStep(string $tenantId, string $workflowId): ?string;

    /** Retourne l'étape suivante, ou null quand toutes les étapes ont été validées. */
    public function approve(string $tenantId, string $workflowId, string $stepId, string $actorId): ?string;

    public function returnForRevision(string $tenantId, string $workflowId, string $stepId, string $actorId, string $reason): void;

    /**
     * Après gel, une version/payload différente doit être enregistrée comme une nouvelle version, jamais substituée.
     *
     * @param array<string, mixed> $payload
     */
    public function freezeSnapshot(string $tenantId, string $workflowId, string $version, array $payload): void;

    /** @return array{version: string, payload: array<string, mixed>}|null */
    public function frozenSnapshot(string $tenantId, string $workflowId): ?array;
}
