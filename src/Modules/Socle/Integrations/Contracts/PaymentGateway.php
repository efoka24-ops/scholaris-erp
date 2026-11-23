<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Integrations\Contracts;

/** Paiement mobile money via un agrégateur agréé. Les montants sont des entiers en XAF. */
interface PaymentGateway
{
    public const PENDING = 'pending';

    /**
     * Demande de paiement. Retourne la référence UNIQUE de la transaction (celle que l'agrégateur renverra dans
     * son webhook) et un statut toujours « pending » : seul le webhook confirme un paiement.
     *
     * @return array{provider_reference: string, status: string}
     * @throws \Scholaris\Core\Http\HttpException 422 si l'agrégateur refuse clairement la demande (échec prouvé)
     */
    public function initiate(int $amountXaf, string $channel, string $payerPhone, string $reference): array;

    /**
     * Vérifie la signature d'un webhook entrant puis le traduit en événement normalisé.
     *
     * @param array<string, string> $headers en-têtes de la requête, noms en minuscules
     * @throws \Scholaris\Core\Http\HttpException 401 si la signature est absente ou invalide, 422 si le corps est invalide
     */
    public function parseWebhook(string $rawBody, array $headers): PaymentWebhookEvent;
}
