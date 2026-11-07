<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Contracts;

/**
 * Point d'accroche de la publication des notes (RG-DEL-3 : publication seulement après signature du PV). Le sous-module Deliberation
 * l'enregistre sous la clé de conteneur `higher.grades.publication_guards.<module>` => [classe ou instance, …].
 * Sans aucune garde enregistrée, la publication est refusée (aucun PV ne peut être signé sans Deliberation), sauf paramètre
 * `higher.grades.publish_without_deliberation` = true.
 */
interface PublicationGuard
{
    public const PREFIX = 'higher.grades.publication_guards.';

    /**
     * @param array{id: string, element_id: string, curriculum_id: string, program_id: string, level_id: string, group_id: ?string,
     *     academic_year_id: string, semester_ordinal: int, exam_session: string} $assessment
     * @return string|null motif du refus (en français), null si la publication est permise
     */
    public function refusal(string $tenantId, string $establishmentId, array $assessment): ?string;
}
