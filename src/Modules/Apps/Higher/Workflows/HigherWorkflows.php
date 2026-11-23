<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Workflows;

use Scholaris\Modules\Socle\Workflows\Contracts\Actor;
use Scholaris\Modules\Socle\Workflows\Contracts\Circuit;
use Scholaris\Modules\Socle\Workflows\Contracts\Lock;
use Scholaris\Modules\Socle\Workflows\Contracts\Step;
use Scholaris\Modules\Socle\Workflows\Contracts\WorkflowDefinitions;

/**
 * Circuits de validation de l'IPES (application `higher`). Les valeurs par défaut sont EXACTEMENT les circuits historiques codés
 * en dur (guides notes, délibérations, finances, admissions) : sans réglage, rien ne change. Chaque circuit déclare ce que
 * l'administrateur peut ajuster (qui valide, étape facultative, confirmation récente) et les règles qui restent verrouillées.
 *
 * Les clés d'acteur sont stables (elles sont stockées dans les réglages) ; le droit et le rôle derrière chaque acteur sont ceux
 * des affectations existantes (Policies\RoleMatrix) : choisir un acteur ne donne aucun droit à personne, il désigne seulement
 * la fonction que l'application interroge à cette étape.
 */
final class HigherWorkflows implements WorkflowDefinitions
{
    public const APPLICATION = 'higher';
    public const GRADES = 'grades_lifecycle';
    public const CORRECTION = 'grades_correction';
    public const SCHOLARSHIP = 'billing_scholarship';
    public const ADMISSION = 'admissions_decision';

    public const CHEF_DEPT = 'chef_dept';
    public const DIR_ECOLE = 'dir_ecole';
    public const REGISTRAR = 'scolarite';
    public const EXAMS = 'examens';
    public const ACCOUNTANT = 'comptable';
    public const DIRECTION = 'direction_generale';

    /** @return list<Circuit> */
    public function circuits(): array
    {
        return [$this->grades(), $this->correction(), $this->scholarship(), $this->admission()];
    }

    private function grades(): Circuit
    {
        $validators = fn (): array => [
            new Actor(self::CHEF_DEPT, 'Chef de département', 'Head of department', 'assessment.validate'),
            new Actor(self::DIR_ECOLE, 'Directeur d\'école', 'School director', 'assessment.correction.approve', 'higher_dir_ecole'),
        ];

        return new Circuit(self::APPLICATION, self::GRADES, 'Notes : saisie, validation, verrouillage et publication', 'Marks: entry, validation, locking and publication', [
            new Step('submit', 'Soumission de la saisie', 'Submission of the entry', [
                new Actor('enseignant', 'Enseignant affecté', 'Assigned lecturer', 'higher.grades.submit'),
            ], 'enseignant', locks: [
                new Lock(Lock::ACTOR, 'assigned_teacher', 'Seul l\'enseignant affecté à l\'élément constitutif soumet sa saisie.', 'Only the lecturer assigned to the course unit submits the entry.'),
            ]),
            new Step('validate', 'Validation de la saisie', 'Validation of the entry', $validators(), self::CHEF_DEPT, optional: true),
            new Step('lock', 'Verrouillage des notes', 'Locking of the marks', $validators(), self::CHEF_DEPT, confirmation: true, confirmationMin: true, locks: [
                new Lock(Lock::CONFIRMATION, 'lock_needs_confirmation', 'Le verrouillage rend les notes immuables : la confirmation récente est toujours exigée.', 'Locking makes the marks immutable: recent confirmation is always required.'),
            ]),
            new Step('publish', 'Publication des résultats', 'Publication of the results', [
                new Actor(self::REGISTRAR, 'Service de la scolarité', 'Registrar\'s office', 'higher.grades.publish'),
                new Actor(self::EXAMS, 'Service des examens', 'Examinations office', 'higher.exam.manage'),
            ], self::REGISTRAR, confirmation: true),
        ], [
            new Lock(Lock::CIRCUIT, 'segregation', 'Séparation des tâches : qui a saisi une note de l\'évaluation ne la verrouille pas.', 'Segregation of duties: whoever entered a mark of the assessment does not lock it.'),
            new Lock(Lock::CIRCUIT, 'minutes_signed', 'Les notes d\'examen ne sont publiées qu\'après la signature du procès-verbal du jury.', 'Exam marks are published only after the jury minutes are signed.'),
            new Lock(Lock::CIRCUIT, 'immutable', 'Une note verrouillée ne se modifie que par une correction motivée, validée.', 'A locked mark can only be changed by a reasoned, approved correction.'),
            new Lock(Lock::CIRCUIT, 'audit', 'Chaque transition est enregistrée au journal d\'audit avec son auteur.', 'Every transition is recorded in the audit log with its author.'),
        ], ['higher']);
    }

    private function correction(): Circuit
    {
        $approvers = fn (): array => [
            new Actor(self::CHEF_DEPT, 'Chef de département', 'Head of department', 'assessment.correction.approve', 'higher_chef_dept'),
            new Actor(self::DIR_ECOLE, 'Directeur d\'école', 'School director', 'assessment.correction.approve', 'higher_dir_ecole'),
        ];

        return new Circuit(self::APPLICATION, self::CORRECTION, 'Correction d\'une note verrouillée', 'Correction of a locked mark', [
            new Step('request', 'Demande motivée avec pièce', 'Reasoned request with supporting document', [
                new Actor('enseignant', 'Enseignant affecté', 'Assigned lecturer', 'assessment.enter'),
            ], 'enseignant', locks: [
                new Lock(Lock::ACTOR, 'assigned_teacher', 'Seul l\'enseignant affecté demande la correction, avec un motif et une pièce.', 'Only the assigned lecturer requests a correction, with a reason and a document.'),
            ]),
            new Step('department', 'Première validation', 'First approval', $approvers(), self::CHEF_DEPT),
            new Step('school', 'Seconde validation', 'Second approval', $approvers(), self::DIR_ECOLE, optional: true, confirmation: true),
        ], [
            new Lock(Lock::CIRCUIT, 'requester', 'Le demandeur ne valide jamais sa propre demande.', 'The requester never approves their own request.'),
            new Lock(Lock::CIRCUIT, 'distinct', 'Une même personne ne valide pas deux étapes de la même demande.', 'The same person never approves two steps of one request.'),
            new Lock(Lock::CIRCUIT, 'no_overwrite', 'La note d\'origine n\'est jamais écrasée : la correction crée une nouvelle version liée, avec auteur, motif et pièce.', 'The original mark is never overwritten: the correction creates a linked new version with author, reason and document.'),
            new Lock(Lock::CIRCUIT, 'audit', 'Chaque validation est enregistrée au journal d\'audit.', 'Every approval is recorded in the audit log.'),
        ], ['higher']);
    }

    private function scholarship(): Circuit
    {
        $accountants = fn (): array => [
            new Actor(self::ACCOUNTANT, 'Comptable', 'Accountant', 'billing.manage'),
            new Actor(self::DIRECTION, 'Direction générale', 'General management', 'higher.settings.sensitive'),
        ];

        return new Circuit(self::APPLICATION, self::SCHOLARSHIP, 'Bourses, remises et échéanciers négociés', 'Scholarships, discounts and negotiated payment plans', [
            new Step('request', 'Demande', 'Request', [
                new Actor(self::ACCOUNTANT, 'Comptable', 'Accountant', 'billing.manage'),
            ], self::ACCOUNTANT, locks: [
                new Lock(Lock::ACTOR, 'accountant_request', 'La demande est déposée par un comptable de l\'établissement.', 'The request is filed by an accountant of the institution.'),
            ]),
            new Step('approval', 'Validation', 'Approval', $accountants(), self::ACCOUNTANT),
        ], [
            new Lock(Lock::CIRCUIT, 'two_people', 'Deux personnes distinctes : celui qui valide n\'est jamais le demandeur.', 'Two distinct people: the approver is never the requester.'),
            new Lock(Lock::CIRCUIT, 'cap', 'Le plafond cumulé des bourses et remises reste appliqué à chaque demande.', 'The cumulative cap on scholarships and discounts still applies to every request.'),
            new Lock(Lock::CIRCUIT, 'audit', 'Chaque demande et chaque décision est enregistrée au journal d\'audit.', 'Every request and decision is recorded in the audit log.'),
        ], ['higher']);
    }

    private function admission(): Circuit
    {
        return new Circuit(self::APPLICATION, self::ADMISSION, 'Décision d\'admission', 'Admission decision', [
            new Step('decision', 'Décision motivée', 'Reasoned decision', [
                new Actor(self::DIR_ECOLE, 'Directeur d\'école', 'School director', 'higher.admission.decide'),
                new Actor(self::REGISTRAR, 'Service de la scolarité', 'Registrar\'s office', 'higher.application.manage'),
            ], self::DIR_ECOLE),
        ], [
            new Lock(Lock::CIRCUIT, 'prerequisites', 'Un candidat n\'est admis que si les prérequis sont satisfaits et toutes les pièces bloquantes vérifiées.', 'A candidate is admitted only if the prerequisites are met and all blocking documents are verified.'),
            new Lock(Lock::CIRCUIT, 'reason', 'Toute décision est motivée ; au-delà des places, un motif de dépassement est obligatoire.', 'Every decision is reasoned; beyond the seats, an over-capacity reason is mandatory.'),
            new Lock(Lock::CIRCUIT, 'audit', 'Chaque décision est enregistrée au journal d\'audit.', 'Every decision is recorded in the audit log.'),
        ], ['higher']);
    }
}
