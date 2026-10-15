<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Documents (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'documents';
    }

    protected function tables(): array
    {
        return [
            'document_types' => self::table('id tenant_id establishment_id code label category type_letter number_template qr_enabled blockable variables created_at updated_at'),
            'document_templates' => self::table('id tenant_id establishment_id type_code locale html version validated_at created_by created_at'),
            'documents' => self::table('id tenant_id establishment_id type_code category number verification_token fingerprint status template_id template_version locale subject_person_id holder_name holder_mask data_json content revoke_reason revoked_at revoked_by replaced_by issued_by issued_via issued_at signed_at'),
            'document_requests' => self::table('id tenant_id establishment_id type_code subject_person_id requested_by note status document_id decided_by decided_at created_at'),
            'document_batches' => self::table('id tenant_id establishment_id type_code locale status total processed failed excluded created_by created_at finished_at'),
            'document_batch_items' => self::table('id tenant_id batch_id subject_person_id data_json status reason document_id'),
            'document_jobs' => self::table('id tenant_id establishment_id batch_id status attempts locked_at last_error created_at updated_at finished_at'),
            'document_seals' => self::table('tenant_id establishment_id signer_name signer_title signature_image stamp_image updated_at', ['order' => ['establishment_id']]),
            'verification_log' => self::table('id tenant_id token outcome ip_hash consulted_at'),
        ];
    }
}
