<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Billing\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Billing (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'billing';
    }

    protected function tables(): array
    {
        return [
            'fee_items' => self::table('id tenant_id establishment_id kind code name amount_xaf active created_at updated_at'),
            'fee_schedules' => self::table('id tenant_id establishment_id academic_year_id structure_id level track fee_item_id amount_xaf terms created_at updated_at'),
            'invoices' => self::table('id tenant_id establishment_id enrollment_id learner_id academic_year_id structure_id status gross_xaf discount_xaf net_xaf paid_xaf balance_xaf created_at updated_at invoice_kind payer_kind payer_name payer_ref'),
            'invoice_lines' => self::table('id tenant_id establishment_id invoice_id fee_item_id fee_code kind label gross_xaf discount_xaf net_xaf'),
            'installments' => self::table('id tenant_id establishment_id invoice_id line_id learner_id kind seq_no label due_on amount_xaf paid_xaf'),
            'discounts' => self::table('id tenant_id establishment_id invoice_id learner_id kind mode rate_value applies_to amount_xaf status reason approval_id requested_by approved_by created_at'),
            'payment_references' => self::table('id tenant_id establishment_id guarantor_id reference created_at'),
            'payments' => self::table('id tenant_id establishment_id payer_reference_id learner_id invoice_id channel amount_xaf credit_xaf status external_reference idempotency_key payer_phone cash_session_id received_by received_at reconciled_at created_at'),
            'allocations' => self::table('id tenant_id establishment_id payment_id installment_id learner_id amount_xaf rule_version created_at'),
            'receipts' => self::table('id tenant_id establishment_id payment_id year seq_no number amount_xaf status issued_at pdf_path sms_sent_at'),
            'cash_sessions' => self::table('id tenant_id establishment_id register_no cashier_id opened_at float_xaf status closed_at expected_xaf counted_xaf variance_xaf variance_user_id note'),
            'cash_deposits' => self::table('id tenant_id establishment_id session_id amount_xaf reference deposited_by deposited_at'),
            'reversals' => self::table('id tenant_id establishment_id payment_id amount_xaf reason status approval_id requested_by approved_by created_at decided_at'),
            'reconciliations' => self::table('id tenant_id establishment_id channel source imported_by imported_at lines_total matched unmatched'),
            'reconciliation_lines' => self::table('id tenant_id establishment_id reconciliation_id line_date operator_reference amount_xaf phone family_reference status payment_id'),
            'reminders' => self::table('id tenant_id establishment_id installment_id offset_days due_on status sent_at'),
            'webhook_events' => self::table('id provider event_id tenant_id outcome payment_id received_at'),
        ];
    }
}
