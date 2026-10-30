<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Pré-remplissage des lignes d'un conseil de classe (SS6, PS4), enregistré sous `assessment.council_line_providers.<application>`.
 * Appelé à l'ouverture et à chaque recalcul (`refresh`, validation) : `conduct` et `appraisal` ne remplissent qu'une valeur vide ;
 * `extras` (distinction refusée, alerte, heures d'absence...) est enregistré dans `council_lines.line_extras` : une clé déjà
 * présente n'est jamais écrasée (saisie en séance conservée), seules les clés absentes sont ajoutées.
 */
interface CouncilLineProvider
{
    /**
     * @param list<string> $personIds
     * @return array<string, array{conduct?: mixed, appraisal?: mixed, extras?: array<string, mixed>}>
     */
    public function prefill(string $tenantId, string $establishmentId, string $structureId, string $periodId, array $personIds): array;
}
