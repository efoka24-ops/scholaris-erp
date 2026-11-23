<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Documents\Application;

use Scholaris\Modules\Apps\Secondary\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Secondary\Documents\Contracts\SecondaryDocumentType as T;

/**
 * Documents du lot E1 (entrée et finances, CDC « Documents ») : DOC-01 à DOC-06, DOC-27 à DOC-29, tous du noyau. QR selon le CDC :
 * DOC-03, 04, 05, 27, 29. Les modèles FR/EN sont amorcés par `database/migrations/202610291301_secondary_document_types.sql`.
 */
final class EntryDocumentTypes implements DocumentTypeProvider
{
    public const PREREGISTRATION = 'preregistration';
    public const ENROLMENT_FORM = 'enrolment_form';
    public const SCHOOL_CERTIFICATE = 'school_certificate';
    public const SCHOOL_CARD = 'school_card';
    public const EXEAT = 'exeat';
    public const CLASS_LIST = 'class_list';
    public const RECEIPT = 'receipt';
    public const ACCOUNT_STATEMENT = 'account_statement';
    public const PAYMENT_ATTESTATION = 'payment_attestation';

    private const SCHOOL_OFFICE = ['secondary.documents.issue', 'secondary.enrol.manage'];
    private const BURSAR = ['secondary.billing.manage'];

    public function types(): array
    {
        return [
            new T('DOC-01', self::PREREGISTRATION, 'sec_doc01_preregistration', 'Fiche de préinscription et résultat du test d\'entrée', 'Pre-registration form and entrance test result', false, T::SOURCE_CALLER, self::SCHOOL_OFFICE, null, false),
            new T('DOC-02', self::ENROLMENT_FORM, 'sec_doc02_enrolment_form', 'Fiche d\'inscription', 'Enrolment form', false, T::SOURCE_ENROLMENT, self::SCHOOL_OFFICE),
            new T('DOC-03', self::SCHOOL_CERTIFICATE, 'sec_doc03_school_certificate', 'Certificat de scolarité', 'School attendance certificate', true, T::SOURCE_ENROLMENT, self::SCHOOL_OFFICE),
            new T('DOC-04', self::SCHOOL_CARD, 'sec_doc04_school_card', 'Carte scolaire', 'School card', true, T::SOURCE_ENROLMENT, self::SCHOOL_OFFICE),
            new T('DOC-05', self::EXEAT, 'sec_doc05_exeat', 'Exeat (certificat de sortie)', 'Exeat (leaving certificate)', true, T::SOURCE_ENROLMENT, self::SCHOOL_OFFICE),
            new T('DOC-06', self::CLASS_LIST, 'sec_doc06_class_list', 'Liste de classe', 'Class list', false, T::SOURCE_CLASS, self::SCHOOL_OFFICE),
            new T('DOC-27', self::RECEIPT, null, 'Reçu de paiement', 'Payment receipt', true, T::SOURCE_SOCLE, self::BURSAR, null, false),
            new T('DOC-28', self::ACCOUNT_STATEMENT, 'sec_doc28_account_statement', 'Échéancier et relevé de compte', 'Payment schedule and account statement', false, T::SOURCE_INVOICE, self::BURSAR),
            new T('DOC-29', self::PAYMENT_ATTESTATION, 'sec_doc29_payment_attestation', 'Attestation de paiement ou quitus', 'Payment certificate or clearance', true, T::SOURCE_INVOICE, self::BURSAR),
        ];
    }
}
