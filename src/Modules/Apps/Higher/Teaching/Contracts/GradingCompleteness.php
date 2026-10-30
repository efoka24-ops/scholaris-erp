<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Contracts;

/**
 * Point d'extension de RG-ENS-5 (solde des vacations d'un EC conditionné par la saisie complète des notes) : le module qui saisit les
 * notes (Grades) dit si la saisie d'un EC est complète. Enregistrement : entrée de liste du conteneur `higher.teaching.grading_completeness.<module>`
 * => [classe ou instance]. Aucun fournisseur enregistré = aucune retenue (la règle ne peut pas être évaluée).
 */
interface GradingCompleteness
{
    public const PREFIX = 'higher.teaching.grading_completeness.';

    /** @return bool|null vrai si toutes les notes de l'EC (et du groupe s'il est donné) sont saisies ; null si inconnu */
    public function isComplete(string $tenantId, string $establishmentId, string $elementId, ?string $groupId = null): ?bool;
}
