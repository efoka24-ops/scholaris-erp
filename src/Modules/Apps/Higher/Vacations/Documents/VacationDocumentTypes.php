<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Vacations\Documents;

use Scholaris\Modules\Apps\Higher\Documents\Contracts\DocumentTypeProvider;
use Scholaris\Modules\Apps\Higher\Documents\Contracts\HigherDocumentType;

/**
 * Documents des vacations (tableau des documents du CDC) : DOC-25 état mensuel des vacations (chef de département, directeur d'école),
 * DOC-26 bulletin de paiement de vacation (comptable). Sans QR ni blocage pour impayé. DOC-24 contrat de vacation (Should ; directeur d'école ; QR ; jamais bloqué) : émis depuis l'état payable.
 */
final class VacationDocumentTypes implements DocumentTypeProvider
{
    public const CONTRACT = 'DOC-24';
    public const STATEMENT = 'DOC-25';
    public const PAYSLIP = 'DOC-26';

    public function types(): array
    {
        return [
            new HigherDocumentType(
                self::CONTRACT,
                'CVA',
                'Contrat de vacation',
                'Teaching hours contract',
                'vacations',
                'higher_dir_ecole',
                true,
                false,
                ['period_month', 'hours_summary', 'hetd_total', 'hourly_rate', 'gross_amount'],
                [
                    'fr' => '<p>Contrat de vacation conclu avec <strong>{{holder_name}}</strong> pour la période {{period_month}}.</p><p>Enseignements confiés : {{hours_summary}}.</p><p>Volume : {{hetd_total}} HETD au taux de {{hourly_rate}} FCFA, soit {{gross_amount}} FCFA bruts avant retenues.</p><p>Le directeur d\'école. Le vacataire.</p>',
                    'en' => '<p>Teaching hours contract with <strong>{{holder_name}}</strong> for the period {{period_month}}.</p><p>Assigned teaching: {{hours_summary}}.</p><p>Volume: {{hetd_total}} TD-equivalent hours at {{hourly_rate}} XAF, i.e. {{gross_amount}} XAF gross before deductions.</p><p>School director. Part-time lecturer.</p>',
                ],
                'other',
                typeLetter: 'V',
            ),
            new HigherDocumentType(
                self::STATEMENT,
                'ETV',
                'État mensuel des vacations',
                'Monthly teaching hours statement',
                'vacations',
                'higher_dir_ecole',
                false,
                false,
                ['period_month', 'hours_summary', 'hetd_total', 'hourly_rate', 'gross_amount', 'deductions_amount', 'net_amount'],
                [
                    'fr' => '<p>État des vacations de <strong>{{holder_name}}</strong> pour {{period_month}}.</p><p>{{hours_summary}}</p><p>Total : {{hetd_total}} HETD au taux de {{hourly_rate}} FCFA, brut {{gross_amount}} FCFA, retenues {{deductions_amount}} FCFA, net {{net_amount}} FCFA.</p><p>Le chef de département. Le directeur d\'école.</p>',
                    'en' => '<p>Teaching hours statement of <strong>{{holder_name}}</strong> for {{period_month}}.</p><p>{{hours_summary}}</p><p>Total: {{hetd_total}} TD-equivalent hours at {{hourly_rate}} XAF, gross {{gross_amount}} XAF, deductions {{deductions_amount}} XAF, net {{net_amount}} XAF.</p><p>Head of department. School director.</p>',
                ],
                'other',
                typeLetter: 'V',
            ),
            new HigherDocumentType(
                self::PAYSLIP,
                'BVA',
                'Bulletin de paiement de vacation',
                'Teaching hours payslip',
                'vacations',
                'comptable',
                false,
                false,
                ['period_month', 'hetd_total', 'gross_amount', 'deductions_amount', 'net_amount', 'payment_method', 'payment_reference', 'paid_on'],
                [
                    'fr' => '<p><strong>{{holder_name}}</strong> : vacations de {{period_month}}, {{hetd_total}} HETD.</p><p>Brut {{gross_amount}} FCFA, retenues {{deductions_amount}} FCFA, net payé {{net_amount}} FCFA le {{paid_on}} ({{payment_method}}, référence {{payment_reference}}).</p><p>Le comptable.</p>',
                    'en' => '<p><strong>{{holder_name}}</strong>: teaching hours for {{period_month}}, {{hetd_total}} TD-equivalent hours.</p><p>Gross {{gross_amount}} XAF, deductions {{deductions_amount}} XAF, net paid {{net_amount}} XAF on {{paid_on}} ({{payment_method}}, reference {{payment_reference}}).</p><p>The accountant.</p>',
                ],
                'other',
                typeLetter: 'V',
            ),
        ];
    }
}
