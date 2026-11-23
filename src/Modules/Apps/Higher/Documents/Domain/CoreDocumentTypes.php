<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Documents\Domain;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents du lot I1 « entrée et inscription » (DOC-01 à DOC-06, tableau des documents du CDC) : signataire, QR, blocage.
 * Modèles de travail (decisions.md R35) : aucune mention légale inventée ; l'en-tête et le pied officiels viennent du profil
 * documentaire de l'école. Le certificat de scolarité n'est jamais bloqué pour impayé (RG-FIN-5, RG-DOC-7).
 */
final class CoreDocumentTypes implements DocumentTypeProvider
{
    public const ACKNOWLEDGEMENT = 'DOC-01';
    public const CONTEST_NOTICE = 'DOC-02';
    public const ADMISSION_LETTER = 'DOC-03';
    public const ENROLMENT_FORM = 'DOC-04';
    public const STUDENT_CARD = 'DOC-05';
    public const SCHOOL_CERTIFICATE = 'DOC-06';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::ACKNOWLEDGEMENT,
                'ACR',
                'Accusé de réception de candidature',
                'Application acknowledgement',
                'admissions',
                null,
                false,
                false,
                ['application_ref', 'submitted_on'],
                [
                    'fr' => '<p>Nous accusons réception du dossier de candidature complet de <strong>{{holder_name}}</strong>, référence {{application_ref}}, déposé le {{submitted_on}} pour la filière {{program_name}} ({{level_name}}), année académique {{academic_year}}.</p><p>Ce document ne vaut pas admission.</p>',
                    'en' => '<p>We acknowledge receipt of the complete application of <strong>{{holder_name}}</strong>, reference {{application_ref}}, submitted on {{submitted_on}} for the programme {{program_name}} ({{level_name}}), academic year {{academic_year}}.</p><p>This document does not constitute admission.</p>',
                ],
                'other',
                typeLetter: 'A',
            ),
            new HigherDocumentType(
                self::CONTEST_NOTICE,
                'CVC',
                'Convocation au concours',
                'Entrance examination notice',
                'admissions',
                'directeur_ecole',
                true,
                false,
                ['contest_name', 'contest_date', 'contest_center', 'seat_number'],
                [
                    'fr' => '<p><strong>{{holder_name}}</strong> est convoqué(e) au concours {{contest_name}} le {{contest_date}}, centre {{contest_center}}, table n° {{seat_number}}.</p><p>Se présenter muni(e) de la présente convocation et d\'une pièce d\'identité.</p>',
                    'en' => '<p><strong>{{holder_name}}</strong> is invited to sit the {{contest_name}} entrance examination on {{contest_date}}, centre {{contest_center}}, seat {{seat_number}}.</p><p>Please bring this notice and an identity document.</p>',
                ],
                'other',
                typeLetter: 'C',
            ),
            new HigherDocumentType(
                self::ADMISSION_LETTER,
                'ADM',
                'Lettre de décision d\'admission',
                'Admission decision letter',
                'admissions',
                'directeur_ecole',
                true,
                false,
                ['decision', 'decision_reason', 'response_deadline'],
                [
                    'fr' => '<p>Suite à l\'examen de votre candidature en filière {{program_name}} ({{level_name}}), année académique {{academic_year}}, la décision suivante a été prise : <strong>{{decision}}</strong>.</p><p>Motif : {{decision_reason}}</p><p>Réponse attendue avant le {{response_deadline}}.</p>',
                    'en' => '<p>Following the review of your application for {{program_name}} ({{level_name}}), academic year {{academic_year}}, the following decision has been made: <strong>{{decision}}</strong>.</p><p>Reason: {{decision_reason}}</p><p>Please reply before {{response_deadline}}.</p>',
                ],
                'other',
                typeLetter: 'L',
            ),
            new HigherDocumentType(
                self::ENROLMENT_FORM,
                'INS',
                'Fiche d\'inscription et contrat pédagogique',
                'Enrolment form and learning agreement',
                'enrolment',
                'scolarite',
                false,
                true,
                ['study_mode', 'units_list'],
                [
                    'fr' => '<p>Étudiant(e) : <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}.</p><p>Filière : {{program_name}} ; niveau : {{level_name}} ; mode : {{study_mode}} ; année académique {{academic_year}}.</p><p>Unités d\'enseignement retenues : {{units_list}}</p><p>Signature de l\'étudiant(e) : ____________________</p>',
                    'en' => '<p>Student: <strong>{{holder_name}}</strong>, registration number {{holder_matricule}}.</p><p>Programme: {{program_name}}; level: {{level_name}}; mode: {{study_mode}}; academic year {{academic_year}}.</p><p>Selected course units: {{units_list}}</p><p>Student signature: ____________________</p>',
                ],
                'other',
                typeLetter: 'F',
            ),
            new HigherDocumentType(
                self::STUDENT_CARD,
                'CAR',
                'Carte d\'étudiant',
                'Student card',
                'enrolment',
                'directeur_ecole',
                true,
                true,
                ['valid_until'],
                [
                    'fr' => '<p><strong>{{holder_name}}</strong></p><p>Matricule : {{holder_matricule}}</p><p>{{program_name}} — {{level_name}}</p><p>Année académique {{academic_year}}, valable jusqu\'au {{valid_until}}.</p>',
                    'en' => '<p><strong>{{holder_name}}</strong></p><p>Registration number: {{holder_matricule}}</p><p>{{program_name}} — {{level_name}}</p><p>Academic year {{academic_year}}, valid until {{valid_until}}.</p>',
                ],
                'other',
                typeLetter: 'K',
            ),
            new HigherDocumentType(
                self::SCHOOL_CERTIFICATE,
                'CER',
                'Certificat de scolarité',
                'Certificate of enrolment',
                'enrolment',
                'directeur_ecole',
                true,
                false,
                ['birth_date', 'birth_place'],
                [
                    'fr' => '<p>Le directeur de l\'école certifie que <strong>{{holder_name}}</strong>, né(e) le {{birth_date}} à {{birth_place}}, matricule {{holder_matricule}}, est régulièrement inscrit(e) en {{program_name}}, {{level_name}}, pour l\'année académique {{academic_year}}.</p><p>En foi de quoi le présent certificat lui est délivré pour servir et valoir ce que de droit.</p>',
                    'en' => '<p>The director of the school certifies that <strong>{{holder_name}}</strong>, born on {{birth_date}} in {{birth_place}}, registration number {{holder_matricule}}, is duly enrolled in {{program_name}}, {{level_name}}, for the academic year {{academic_year}}.</p><p>This certificate is issued for all legal purposes.</p>',
                ],
                typeLetter: 'S',
            ),
        ];
    }
}
