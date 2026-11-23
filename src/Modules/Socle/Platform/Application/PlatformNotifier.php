<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Platform\Application;

use Psr\Log\LoggerInterface;
use Scholaris\Modules\Socle\Messaging\Contracts\DeliveryResult;
use Scholaris\Modules\Socle\Messaging\Contracts\DirectNotifier;
use Scholaris\Modules\Socle\Platform\Infrastructure\PlatformDb;
use Throwable;

/**
 * Notifications de la plateforme par Messaging (modèles `platform_*`). Un échec d'envoi n'annule jamais l'opération
 * métier : il est journalisé et renvoyé comme « blocked ».
 */
final class PlatformNotifier
{
    public function __construct(
        private readonly DirectNotifier $notifier,
        private readonly PlatformDb $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, scalar> $variables */
    public function send(string $template, string $locale, ?string $email, ?string $phone, array $variables): string
    {
        try {
            return $this->notifier->notify($template, $locale, $email, $phone, $variables)->status;
        } catch (Throwable $e) {
            $this->logger->warning('Notification de plateforme non envoyée', ['template' => $template, 'error' => $e::class]);

            return DeliveryResult::BLOCKED;
        }
    }

    /**
     * Notification au contact de facturation d'un client (enregistré à l'acceptation de sa demande d'accès).
     *
     * @param array<string, scalar> $variables
     */
    public function toClient(string $tenantId, string $template, array $variables): string
    {
        $contact = $this->db->one('SELECT contact_email, contact_phone, locale FROM platform_clients WHERE tenant_id = ?', [$tenantId]);
        if ($contact === null) {
            return DeliveryResult::BLOCKED;
        }

        return $this->send(
            $template,
            (string) $contact['locale'],
            $contact['contact_email'] === null ? null : (string) $contact['contact_email'],
            $contact['contact_phone'] === null ? null : (string) $contact['contact_phone'],
            $variables,
        );
    }
}
