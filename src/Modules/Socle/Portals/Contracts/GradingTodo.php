<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Portals\Contracts;

/**
 * Contrat SORTANT local : saisies de notes que l'enseignant doit encore faire (évaluations de ses classes sans notes complètes).
 * À implémenter par Assessment et à enregistrer dans son definitions.php sous une entrée de liste `portals.grading_todo.assessment`
 * (tableau de noms de classes ; voir GradingTodoRegistry). Réponse null = non évaluable : le portail l'indique explicitement au lieu de montrer une liste vide.
 */
interface GradingTodo
{
    /** @return list<array{assessment_id: string, label: string, establishment_id: string, structure_id: ?string, missing_grades: int}>|null */
    public function pendingFor(string $tenantId, string $teacherPersonId): ?array;
}
