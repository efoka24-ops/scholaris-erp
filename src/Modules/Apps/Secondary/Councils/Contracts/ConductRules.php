<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Contracts;

/**
 * Règles de conduite de Councils offertes aux autres sous-modules (Discipline, T865) en LECTURE : barème des heures d'absence
 * injustifiées (ConductScale, RG-PRE-1) et cycle d'une sanction proposée (SanctionProposal, RG-PRE-2). La logique reste dans
 * `Councils/Domain` ; ce contrat n'écrit rien. Une proposition est décrite par le tableau
 * `{person_id, code, label, level, hours, status, decided_by, reason}` (status : proposed, applied, rejected).
 */
interface ConductRules
{
    public const PROPOSED = 'proposed';
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';

    /**
     * Barème d'EXEMPLE (« à confirmer », jamais appliqué d'office) : point de départ proposé à l'établissement.
     *
     * @return array{tiers: list<array<string, mixed>>, repeat_offence_code?: string}
     */
    public function exampleScale(): array;

    /**
     * @param array<string, mixed> $scale `{tiers: [...], repeat_offence_code?}`
     * @return list<string> erreurs (liste vide = barème utilisable)
     */
    public function scaleErrors(array $scale): array;

    /**
     * Proposition du barème pour ces heures (le dernier palier atteint ; récidive : palier de récidive), ou null.
     *
     * @param array<string, mixed> $scale
     * @return array{person_id: string, code: string, label: string, level: string, hours: float, status: string, decided_by: ?string, reason: ?string}|null
     *
     * @throws \InvalidArgumentException barème invalide
     */
    public function propose(array $scale, string $personId, float $unjustifiedHours, bool $repeatOffence = false): ?array;

    /**
     * Décision explicite d'une personne habilitée sur une proposition ouverte : appliquée, ou rejetée avec un motif.
     *
     * @param array{person_id: string, code: string, label: string, level: string, hours: float, status: string} $proposal
     * @return array{person_id: string, code: string, label: string, level: string, hours: float, status: string, decided_by: ?string, reason: ?string}
     *
     * @throws \DomainException déjà décidée, ou rejet sans motif
     */
    public function decide(array $proposal, bool $apply, string $decidedBy, ?string $reason = null): array;
}
