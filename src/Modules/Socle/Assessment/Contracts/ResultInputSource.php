<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Notes d'une classe tenues par une application (C-116) : enregistrée sous `assessment.result_input_sources.<application>`.
 * Quand une source répond pour une structure, une période et une session, le calcul des résultats, la délibération en séance
 * et la publication du socle utilisent ses unités et ses règles au lieu des épreuves du socle (qui n'existent pas pour cette
 * classe) ; la publication ne fait alors pas transiter d'épreuves du socle (le cycle des notes reste celui de l'application).
 * Une source qui ne connaît pas la classe répond null : le comportement du socle est inchangé.
 */
interface ResultInputSource
{
    public function inputs(string $tenantId, string $establishmentId, string $structureId, string $periodId, string $session): ?ResultInputs;
}
