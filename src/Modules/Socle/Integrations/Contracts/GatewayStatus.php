<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/**
 * État des passerelles d'envoi pour le back-office de la plateforme (C-112) : pilote choisi, configuration complète,
 * état du compte TeeeOTP (GET /v1/me : mode, quota restant) et aperçu du rendu HTML d'un e-mail. Jamais de secret rendu.
 */
interface GatewayStatus
{
    /**
     * Interroge TeeeOTP si c'est le pilote actif (appel réseau : l'appelant met le résultat en cache).
     *
     * @return array{driver: string, configured: bool, reachable: ?bool, mode: ?string, quota_remaining: ?int, error: ?string}
     */
    public function sms(): array;

    /** @return array{driver: string, configured: bool} */
    public function mail(): array;

    /** Rendu HTML d'un e-mail transactionnel (même gabarit que l'envoi SMTP). */
    public function emailPreview(string $subject, string $body): string;
}
