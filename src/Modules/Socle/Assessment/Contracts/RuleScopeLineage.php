<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Contracts;

/**
 * Rattachement des règles de calcul à une structure (S5) : pour un groupe, une application indique les structures dont
 * les règles s'appliquent, de la plus précise à la plus large (groupe, filière, programme). Enregistrée dans le conteneur
 * sous `assessment.rule_scopes.<module>` ; le premier fournisseur qui répond une liste non vide l'emporte. Sans
 * fournisseur, seule la structure elle-même est cherchée.
 */
interface RuleScopeLineage
{
    /** @return list<string> identifiants de structures, la plus précise d'abord ; vide si ce fournisseur ne la connaît pas */
    public function scopesOf(string $tenantId, string $establishmentId, string $structureId): array;
}
