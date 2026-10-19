<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Contracts;

/**
 * Écriture des notes de l'année EN COURS par la REPRISE DE DONNÉES (lot I4). Contrat additif, réservé à Higher\Migration.
 * Chaque évaluation reprise est créée directement à l'état « verrouillé » (jamais publié : la publication reste une décision de
 * l'établissement) ; chaque note porte l'événement « reprise » avec son motif. Une correction ultérieure suit la procédure normale
 * (demande motivée, double validation).
 */
interface MarkImporter
{
    public const EVENT = 'reprise';

    /**
     * Motif de refus de la cible (maquette publiée absente, EC inconnu, groupe d'un autre niveau, type d'évaluation inconnu), null si permis.
     *
     * @param string $kind cc, tp ou exam
     */
    public function checkTarget(string $tenantId, string $establishmentId, string $programId, string $levelId, ?string $groupId, string $academicYearId, string $elementCode, string $kind): ?string;

    /**
     * Valide une cellule selon le barème de l'école (0 à 20 au pas déclaré, ou code d'absence).
     *
     * @return array{score: ?float, absence: ?string}
     * @throws \Scholaris\Core\Http\HttpException 422 note hors barème, au mauvais pas, ou code inconnu
     */
    public function normalize(string $tenantId, string $establishmentId, string $programId, string $raw): array;

    /**
     * Crée l'évaluation verrouillée de reprise et ses notes. Idempotent : l'évaluation de reprise de ce même EC, groupe et type existe
     * déjà → rien n'est écrit (`created` faux).
     *
     * @param list<array{person_id: string, enrolment_id: ?string, score: ?float, absence: ?string}> $marks
     * @return array{assessment_id: string, created: bool, written: int}
     */
    public function importAssessment(string $tenantId, string $establishmentId, string $actorId, string $programId, string $levelId, ?string $groupId, string $academicYearId, string $elementCode, string $kind, array $marks, string $reason): array;

    /**
     * Annule une évaluation reprise : refusée (409) si elle est publiée ou si une correction ou une requête en dépend.
     *
     * @throws \Scholaris\Core\Http\HttpException 409
     */
    public function revertAssessment(string $tenantId, string $establishmentId, string $actorId, string $assessmentId): void;
}
