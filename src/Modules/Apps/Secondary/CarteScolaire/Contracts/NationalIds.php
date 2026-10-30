<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\CarteScolaire\Contracts;

/**
 * Matricule unique de l'élève (cartescolaire.cm, EX-CSC-01, RG-INS-1), contrat interne de Secondary fourni par CarteScolaire :
 * Enrolment (API-02), OfficialExams (contrôle du matricule), documents. Le matricule n'est jamais placé dans une adresse ni une page
 * publique : on désigne l'élève par l'identifiant interne de la personne. Format contrôlé par le paramètre
 * `secondary.carte_scolaire.national_id_pattern` (« à confirmer »).
 */
interface NationalIds
{
    /** Matricule enregistré, ou null (« en attente »). */
    public function of(string $tenantId, string $establishmentId, string $personId): ?string;

    /** pending, recorded ou mismatch (écart d'identité signalé, RG-CSC-2). */
    public function statusOf(string $tenantId, string $establishmentId, string $personId): string;

    public function personIdByNationalId(string $tenantId, string $establishmentId, string $nationalId): ?string;

    /** Matricule normalisé (majuscules, sans espaces) s'il respecte le format paramétré, sinon null. */
    public function normalize(string $tenantId, string $establishmentId, string $nationalId): ?string;

    /**
     * Enregistre (ou remplace) le matricule d'un élève : format contrôlé (422), unicité dans l'établissement (409) ; les paiements
     * constatés « inconnus » portant ce matricule lui sont rattachés.
     */
    public function record(string $tenantId, string $establishmentId, string $personId, string $nationalId, string $origin, string $actorId): string;
}
