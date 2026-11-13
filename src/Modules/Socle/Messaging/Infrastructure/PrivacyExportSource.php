<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Infrastructure;

use Scholaris\Modules\Socle\Audit\Contracts\TableExportSource;

/**
 * Source d'export complet du module Messaging (tenant:export). Liste blanche de colonnes : aucun secret n'est exporté.
 */
final class PrivacyExportSource extends TableExportSource
{
    public function name(): string
    {
        return 'messaging';
    }

    protected function tables(): array
    {
        return [
            'message_templates' => self::table('id tenant_id establishment_id template_key locale channel subject body variables created_at updated_at'),
            'messages' => self::table('id tenant_id establishment_id created_by template_key body channels variables audience urgent status scheduled_at recipients_count created_at updated_at'),
            'message_deliveries' => self::table('id tenant_id establishment_id message_id recipient_id channel status reason provider_reference variables not_before attempts sent_at delivered_at created_at updated_at'),
            'announcements' => self::table('id tenant_id establishment_id created_by title body published_at expires_at created_at updated_at'),
            'conversations' => self::table('id tenant_id establishment_id subject created_by status archived_at created_at updated_at kind learner_id last_message_at'),
            'conversation_participants' => self::table('id tenant_id establishment_id conversation_id person_id chat_role last_read_message_id created_at'),
            'conversation_messages' => self::table('id tenant_id establishment_id conversation_id sender_id body moderation_status moderated_by created_at attachments moderated_at moderation_reason'),
            'conversation_reports' => self::table('id tenant_id establishment_id conversation_id message_id reported_by reason status resolved_by resolved_at created_at'),
            'sms_usage' => self::table('tenant_id establishment_id usage_month sms_count updated_at', ['order' => ['establishment_id', 'usage_month']]),
        ];
    }
}
