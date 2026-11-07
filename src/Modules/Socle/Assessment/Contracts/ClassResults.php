<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Résultats chiffrés d'une classe pour une période, calculés par le moteur du socle (règles en vigueur de la structure, stratégie
 * qu'elles nomment, agrégat d'une période parente s'il y a lieu) : moyenne générale, rang avec ex æquo, effectif, mention et moyenne
 * par matière. Sert aux applications qui tiennent leur propre conseil (ex. conseil des maîtres du primaire) sans exposer les notes.
 * Les épreuves de session normale seulement. Toujours borné au client et à l'établissement ; une classe sans épreuve chiffrée
 * donne des moyennes nulles. Fourni par le module Assessment (S09).
 */
interface ClassResults
{
    /**
     * @return list<array{person_id: string, average: float|null, rank: int|null, class_size: int, mention: string|null, rule_version: int|null,
     *                    subjects: list<array{subject_id: string, code: string|null, name: string|null, average: float|null}>}>
     */
    public function compute(string $tenantId, string $establishmentId, string $structureId, string $periodId, ?string $periodLabel = null): array;
}
