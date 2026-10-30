<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

/** Suivi de délivrance : jamais de numéro, d'adresse ni de texte du message. */
final class DeliveryResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'message_id', 'recipient_id', 'channel', 'status', 'reason', 'provider_reference', 'not_before', 'attempts', 'sent_at', 'delivered_at', 'created_at', 'updated_at'];
    }
}
