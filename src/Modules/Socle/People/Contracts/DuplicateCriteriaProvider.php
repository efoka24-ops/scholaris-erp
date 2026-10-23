<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Point d'extension (PP8) : critères de doublon propres à une application, sous une entrée de liste
 * `people.duplicate_criteria.<application>` (voir PREFIX). Sans fournisseur, la détection du socle (nom, date de naissance,
 * téléphone) est inchangée. Un doublon probable exige toujours la concordance du nom avec au moins un autre critère.
 */
interface DuplicateCriteriaProvider
{
    public const PREFIX = 'people.duplicate_criteria.';

    /**
     * Établissements à l'intérieur desquels chercher les doublons (la personne doit y avoir une inscription), ou null
     * pour ne pas restreindre. Plusieurs fournisseurs restrictifs : union de leurs établissements.
     *
     * @return list<string>|null
     */
    public function establishmentScope(string $tenantId): ?array;

    /**
     * Personnes à examiner en plus de celles trouvées par date de naissance ou téléphone (ex. celles d'un parent commun).
     *
     * @param array<string, mixed> $input identité saisie
     * @return list<string>
     */
    public function candidateIds(string $tenantId, array $input): array;

    /**
     * Critères supplémentaires concordants (ex. `common_guardian`) entre la saisie et la fiche existante.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return list<string>
     */
    public function extraCriteria(string $tenantId, array $input, array $existing): array;
}
