<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicSchools\Contracts;

/**
 * Élève et identifiant national (ELV), fourni par PublicSchools\Pupils. Toutes les méthodes sont bornées au client : un élève
 * d'un autre client est introuvable (null). Aucune garde de paiement ni de contribution (RG-ELV-1).
 */
interface PupilRegistry
{
    public function find(string $tenantId, string $personId): ?PupilInfo;

    /** Recherche par identifiant national exact (le format vient des fournisseurs `people.learner_number.providers.*`). */
    public function findByLearnerNumber(string $tenantId, string $learnerNumber): ?PupilInfo;

    /**
     * Élèves déjà connus qui ressemblent au brouillon (critères du socle : identité, date de naissance, parent commun), pour
     * proposer un rapprochement avant création ; liste vide si aucun doublon possible.
     *
     * @return list<PupilInfo>
     */
    public function possibleDuplicates(string $tenantId, string $establishmentId, PupilDraft $draft): array;

    /**
     * Inscrit un élève dans une école (création de la personne, attribution de l'identifiant national, inscription dans la classe
     * éventuelle). Idempotent par `clientUuid`. Un âge hors norme ou un acte manquant donnent un avertissement, jamais un refus.
     *
     * @throws \InvalidArgumentException si l'école est fermée ou inconnue, ou si la classe n'appartient pas à l'école
     */
    public function register(string $tenantId, string $establishmentId, PupilDraft $draft, ?string $classId, string $actorId): PupilRegistration;
}
