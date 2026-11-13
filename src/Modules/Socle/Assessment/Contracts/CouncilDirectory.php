<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Lecture des conseils de classe et des résultats enregistrés (SS5, SS6) pour les applications qui produisent les variables de
 * bulletin ou pré-remplissent les lignes : conseil par identifiant (classe, période, lignes avec proposition, décision et valeurs
 * propres), et résultats enregistrés d'une classe pour une période avec les valeurs calculées par la stratégie (`computed_extras`)
 * et le détail par matière. Lecture seule, toujours bornée au client et à l'établissement. Fourni par le module Assessment.
 */
interface CouncilDirectory
{
    /**
     * @return array{id: string, structure_id: string, period_id: string, period_label: string, status: string, held_on: string|null, rule_version: int,
     *               lines: list<array{person_id: string, average: float|null, rank: int|null, unjustified_absences: int, conduct: string|null,
     *                                 proposal: string|null, decision: string|null, reason: string|null, appraisal: string|null, extras: array<string, mixed>}>}|null
     */
    public function find(string $tenantId, string $establishmentId, string $councilId): ?array;

    /**
     * Résultats enregistrés (dernier calcul) d'une classe pour une période, session normale, par identifiant de personne.
     *
     * @return array<string, array{person_id: string, average: float|null, rank: int|null, class_size: int, mention: string|null, proposal: string|null,
     *                              decision: string|null, details: list<array<string, mixed>>, extras: array<string, mixed>}>
     */
    public function storedResults(string $tenantId, string $establishmentId, string $structureId, string $periodId): array;
}
