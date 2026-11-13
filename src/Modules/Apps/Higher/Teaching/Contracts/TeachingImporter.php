<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Teaching\Contracts;

/**
 * Écriture des dossiers enseignants et des affectations par la REPRISE DE DONNÉES (lot I4). Contrat additif, réservé à
 * Higher\Migration. La fiche du personnel est celle du module Personnel du socle : elle doit exister (et être active) avant la reprise.
 * L'affectation s'appuie sur la maquette publiée de la filière, du niveau et de l'année et sur le code de l'élément constitutif.
 */
interface TeachingImporter
{
    /** Motif de refus du dossier enseignant (fiche du personnel absente ou inactive, autorisation de l'employeur manquante), null si permis. */
    public function checkTeacher(string $tenantId, string $personId, string $teacherStatus, bool $publicServant, ?string $authorizationRef, ?string $authorizationDocument): ?string;

    /** Motif de refus d'une affectation (maquette non publiée, EC inconnu, groupe d'un autre niveau, enseignant inactif), null si permis. */
    public function checkAssignment(string $tenantId, string $establishmentId, string $personId, string $programId, string $levelId, string $groupId, string $academicYearId, string $elementCode): ?string;

    /**
     * Crée ou complète le dossier enseignant (statut, grade, autorisation de l'employeur). Idempotent par personne.
     *
     * @return array{profile_id: string, created: bool}
     */
    public function importTeacher(
        string $tenantId,
        string $establishmentId,
        string $actorId,
        string $personId,
        ?string $departmentId,
        string $teacherStatus,
        ?string $grade,
        bool $publicServant,
        ?string $authorizationRef,
        ?string $authorizationDocument,
        ?string $authorizationUntil,
    ): array;

    /**
     * Affecte l'enseignant à un EC et un groupe pour la nature (cm, td, tp) et le volume (minutes). Idempotent : une affectation active
     * identique est reprise.
     *
     * @return array{assignment_id: string, created: bool}
     */
    public function importAssignment(string $tenantId, string $establishmentId, string $actorId, string $personId, string $programId, string $levelId, string $groupId, string $academicYearId, string $elementCode, string $kind, int $minutes): array;

    /** Annule une affectation reprise ; refusée (409) dès qu'un créneau d'emploi du temps ou une séance en dépend. */
    public function revertAssignment(string $tenantId, string $establishmentId, string $actorId, string $assignmentId): void;

    /** Supprime un dossier enseignant créé par la reprise s'il n'a plus aucune affectation. */
    public function revertTeacher(string $tenantId, string $establishmentId, string $profileId): void;
}
