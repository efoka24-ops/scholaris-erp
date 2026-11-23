<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Contracts;

/**
 * Faits d'un élève utiles aux documents et aux portails, fournis par le sous-module qui les tient (Enrolment : matricule national,
 * D5). Enregistrement : clé de liste du conteneur `secondary.student_facts.<module>` => [MonFournisseur::class]. Sans fournisseur,
 * le matricule est « en attente ». Le matricule n'est imprimé que sur les documents remis (carte, fiche d'inscription) : jamais
 * dans une adresse, une réponse de portail ni la page publique de vérification.
 */
interface StudentFacts
{
    /** Matricule national enregistré, ou null s'il est en attente. */
    public function nationalId(string $tenantId, string $establishmentId, string $personId): ?string;
}
