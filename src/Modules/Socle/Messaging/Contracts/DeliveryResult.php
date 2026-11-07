<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Contracts;

/** Résultat d'une demande d'envoi : envoyé, mis en file (plage horaire), ou bloqué (consentement absent, aucun contact). */
final class DeliveryResult
{
    public const SENT = 'sent';
    public const QUEUED = 'queued';
    public const BLOCKED = 'blocked';

    public function __construct(
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $reason = null,
    ) {
    }
}
