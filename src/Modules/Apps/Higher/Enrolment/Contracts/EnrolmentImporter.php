<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Enrolment\Contracts;

/**
 * Écriture d'inscriptions par la REPRISE DE DONNÉES (lot I4) : étudiants déjà en cours à l'ouverture du logiciel. Contrat additif,
 * réservé à Higher\Migration. L'inscription est créée directement « inscrit » (pas de frais d'inscription à contrôler : le solde
 * d'ouverture est repris à part) mais garde la garde d'autorisation MINESUP du niveau (RG-ACA-1/2), l'unicité par filière et
 * année, la version de maquette (RG-MAQ-1) et le contrat pédagogique. Tout est journalisé.
 */
interface EnrolmentImporter
{
    /**
     * Inscrit l'étudiant dans le niveau (et le groupe) pour l'année. Idempotent : une inscription vivante de la même personne dans la
     * même filière et la même année est reprise sans rien modifier (`created` faux).
     *
     * @param string|null $matricule matricule d'origine à conserver ; null = matricule attribué selon le format de l'école (RG-INS-1)
     * @return array{enrolment_id: string, student_id: string, matricule: ?string, created: bool, roll_call: string}
     *     `roll_call` : linked, no_group ou pending:<motif> (inscription du socle, liste d'appel)
     * @throws \Scholaris\Core\Http\HttpException 404 niveau ou groupe, 409 matricule déjà pris par une autre personne, 422 garde d'autorisation
     */
    public function importEnrolled(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $personId,
        string $levelId,
        ?string $groupId,
        string $academicYearId,
        ?string $studyMode,
        ?string $matricule,
        int $attemptNumber,
    ): array;

    /** Motif de refus d'inscrire dans ce niveau et cette année (autorisation MINESUP absente ou expirée, tutelle manquante), null si permis. */
    public function refusal(string $tenantId, string $establishmentId, string $levelId, ?string $groupId, string $academicYearId): ?string;

    /**
     * Reporte une UE capitalisée ou en dette au contrat pédagogique de l'inscription vivante de l'année. Idempotent.
     *
     * @param string $origin `debt` ou `capitalized`
     * @return string|null identifiant de la ligne du contrat, null si l'étudiant n'a pas d'inscription vivante cette année dans la filière
     */
    public function addCarryOver(string $tenantId, string $establishmentId, string $actorId, string $personId, string $academicYearId, string $programId, string $origin, string $unitCode, string $unitTitle, int $credits, string $unitLevelId): ?string;

    /** Retire une ligne reportée par la reprise (annulation du lot). */
    public function removeCarryOver(string $tenantId, string $establishmentId, string $itemId): void;

    /**
     * Annule une inscription reprise : refusée (409) dès qu'une donnée en dépend (décision de passage, autre inscription). Le dossier
     * étudiant est supprimé s'il n'a plus aucune inscription.
     *
     * @throws \Scholaris\Core\Http\HttpException 409 donnée dépendante
     */
    public function revertImported(string $tenantId, string $establishmentId, string $actorId, string $enrolmentId, string $reason): void;
}
