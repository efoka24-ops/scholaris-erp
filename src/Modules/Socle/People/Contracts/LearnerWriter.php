<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Contracts;

/**
 * Écriture d'un élève par une application (PP9) : fiche, garants, inscription, clôture. Mêmes contrôles que les routes
 * `/people`, `/people/{id}/relations` et `/enrollments` (identité valide, doublons, garant d'un mineur, structure autorisée, gardes
 * `organisation.enrolment.guards.*`). RG-ELV-4 : un élève n'a qu'une inscription vivante (active, conditionnelle ou suspendue)
 * à la fois dans l'ensemble des établissements du client ; un transfert clôt d'abord l'inscription d'origine.
 */
interface LearnerWriter
{
    /**
     * Crée la fiche de l'élève et ses garants dans une seule transaction.
     *
     * @param array<string, mixed> $draft identité (first_name, last_name, birth_date, birth_place, gender, nationality, phone, email, locale, national_id)
     * @param list<array{kind: string, person_id?: string, identity?: array<string, mixed>}> $guardians `kind` : parent, guardian, financial_guarantor,
     *        authorized_pickup ou emergency ; `person_id` = personne existante du client, sinon `identity` crée la personne
     * @param bool $confirmDifferent vrai quand l'appelant a vérifié qu'un doublon probable est une personne différente
     * @return array{person_id: string, guardian_ids: list<string>}
     * @throws \Scholaris\Core\Http\HttpException 422 (identité, garant ou type invalide), 409 (doublon probable non confirmé, matricule déjà pris)
     */
    public function createLearner(string $tenantId, array $draft, array $guardians, ?string $actorId, bool $confirmDifferent = false): array;

    /**
     * Inscrit l'élève (brouillon puis validation) et enregistre la date d'entrée (AAAA-MM-JJ). Idempotent par établissement,
     * structure et année : une inscription vivante identique est reprise sans modifier sa date d'entrée.
     *
     * @return string identifiant de l'inscription
     * @throws \Scholaris\Core\Http\HttpException 409 (RG-ELV-4 ou inscription vivante conflictuelle), 422 (date, dossier incomplet, refus d'une garde), 404
     */
    public function enroll(string $tenantId, string $establishmentId, string $personId, string $structureId, string $academicYearId, string $date, ?string $actorId): string;

    /**
     * Clôt une inscription vivante à la date donnée (AAAA-MM-JJ), y compris une inscription conditionnelle (élève sans pièce).
     *
     * @param string $status `transferred` ou `withdrawn`
     * @throws \Scholaris\Core\Http\HttpException 404 (inscription hors client), 409 (déjà close ou brouillon), 422 (statut ou date invalide)
     */
    public function closeEnrollment(string $tenantId, string $enrollmentId, string $status, string $date): void;
}
