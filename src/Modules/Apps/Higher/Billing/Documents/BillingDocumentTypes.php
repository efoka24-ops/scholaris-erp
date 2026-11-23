<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Billing\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents financiers (DOC-27 à DOC-30, EX-FIN-06). Le reçu DOC-27 reste celui du socle (numéro de caisse, /receipts) : déclaré
 * pour le catalogue, jamais émis par Documents. Relevé de compte, reçu et attestation de paiement ne sont jamais bloqués pour
 * impayé (RG-DOC-7) ; le quitus DOC-30 exige un solde nul (QuitusGuard, RG-FIN-6).
 */
final class BillingDocumentTypes implements DocumentTypeProvider
{
    public const RECEIPT = 'DOC-27';
    public const STATEMENT = 'DOC-28';
    public const PAYMENT_CERTIFICATE = 'DOC-29';
    public const QUITUS = 'DOC-30';

    /** Code du type au socle (même règle que HigherDocumentType::socleCode). */
    public static function socleCode(string $code): string
    {
        return 'hi_' . strtolower(str_replace('-', '', $code));
    }

    public function types(): array
    {
        return [
            new HigherDocumentType(self::RECEIPT, 'REC', 'Reçu de paiement', 'Payment receipt', 'billing', null, true, false, issuedBySocle: true),
            new HigherDocumentType(
                self::STATEMENT,
                'RDC',
                'Relevé de compte et échéancier',
                'Statement of account and payment schedule',
                'billing',
                null,
                false,
                false,
                ['statement_lines', 'total_due', 'total_paid', 'balance'],
                [
                    'fr' => '<p>Relevé de compte de <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}, {{program_name}}, année académique {{academic_year}}.</p><p>Échéancier : {{statement_lines}}</p><p>Total dû : {{total_due}} FCFA ; total payé : {{total_paid}} FCFA ; solde : {{balance}} FCFA.</p>',
                    'en' => '<p>Statement of account of <strong>{{holder_name}}</strong>, registration number {{holder_matricule}}, {{program_name}}, academic year {{academic_year}}.</p><p>Schedule: {{statement_lines}}</p><p>Total due: {{total_due}} XAF; total paid: {{total_paid}} XAF; balance: {{balance}} XAF.</p>',
                ],
                'other',
                typeLetter: 'R',
            ),
            new HigherDocumentType(
                self::PAYMENT_CERTIFICATE,
                'ATP',
                'Attestation de paiement',
                'Certificate of payment',
                'billing',
                'comptable',
                true,
                false,
                ['amount_paid', 'payments_list'],
                [
                    'fr' => '<p>Le service de la comptabilité atteste que <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}, inscrit(e) en {{program_name}} pour l\'année académique {{academic_year}}, a versé la somme de {{amount_paid}} FCFA.</p><p>Détail : {{payments_list}}</p>',
                    'en' => '<p>The accounts department certifies that <strong>{{holder_name}}</strong>, registration number {{holder_matricule}}, enrolled in {{program_name}} for the academic year {{academic_year}}, has paid the sum of {{amount_paid}} XAF.</p><p>Details: {{payments_list}}</p>',
                ],
                typeLetter: 'P',
            ),
            new HigherDocumentType(
                self::QUITUS,
                'QUI',
                'Quitus financier',
                'Financial clearance',
                'billing',
                'comptable',
                true,
                false,
                ['purpose'],
                [
                    'fr' => '<p>Le service de la comptabilité certifie que <strong>{{holder_name}}</strong>, matricule {{holder_matricule}}, {{program_name}}, est en règle vis-à-vis de l\'établissement : aucune somme ne reste due à la date de délivrance.</p><p>Quitus délivré pour : {{purpose}}.</p>',
                    'en' => '<p>The accounts department certifies that <strong>{{holder_name}}</strong>, registration number {{holder_matricule}}, {{program_name}}, has no outstanding balance with the institution at the date of issue.</p><p>Clearance issued for: {{purpose}}.</p>',
                ],
                typeLetter: 'Q',
            ),
        ];
    }
}
